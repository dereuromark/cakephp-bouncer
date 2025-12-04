<?php

declare(strict_types=1);

namespace Bouncer\Model\Behavior;

use ArrayObject;
use Bouncer\Model\Entity\BouncerRecord;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\ORM\Behavior;
use Cake\ORM\Locator\LocatorAwareTrait;
use RuntimeException;

/**
 * Bouncer Behavior
 *
 * Intercepts save operations and creates bouncer records for approval instead.
 *
 * @property \Cake\ORM\Table $_table
 */
class BouncerBehavior extends Behavior
{
    use LocatorAwareTrait;

    /**
     * Default configuration.
     *
     * @var array<string, mixed>
     */
    protected array $_defaultConfig = [
        'userField' => 'user_id',
        'mode' => 'intercept',
        'requireApproval' => ['add', 'edit', 'delete'],
        'exemptRoles' => [],
        'exemptUsers' => [],
        'bypassCallback' => null,
        'validateOnDraft' => true,
        'autoSupersede' => true,
    ];

    /**
     * Tracks if the last save was bounced.
     *
     * @var bool
     */
    protected bool $wasBounced = false;

    /**
     * Tracks the last created bouncer record.
     *
     * @var \Bouncer\Model\Entity\BouncerRecord|null
     */
    protected $lastBouncerRecord;

    /**
     * Tracks if last bouncer operation failed (vs. intentionally skipped).
     *
     * @var bool
     */
    protected bool $bouncerFailed = false;

    /**
     * Tracks if the last save operation resulted in a draft being removed (reverted to original).
     *
     * @var bool
     */
    protected bool $draftRemoved = false;

    /**
     * Initialize hook.
     *
     * @param array<string, mixed> $config Configuration
     *
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);
    }

    /**
     * Before save callback.
     *
     * Intercepts the save operation and creates a bouncer record instead.
     *
     * @param \Cake\Event\EventInterface $event The event
     * @param \Cake\Datasource\EntityInterface $entity The entity
     * @param \ArrayObject $options The options
     *
     * @return void
     */
    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        $this->wasBounced = false;
        $this->lastBouncerRecord = null;
        $this->bouncerFailed = false;
        $this->draftRemoved = false;

        // Check if we should bypass bouncer
        if ($this->shouldBypass($entity, $options)) {
            return;
        }

        // Check if this action requires approval
        $isNew = $entity->isNew();
        $action = $isNew ? 'add' : 'edit';

        if (!in_array($action, $this->getConfig('requireApproval'), true)) {
            return;
        }

        // If editing but no dirty fields, check if we should remove an existing pending draft
        if (!$isNew && !$entity->isDirty()) {
            $userId = $options['bouncerUserId'] ?? null;
            if ($userId) {
                $this->removeRevertedDraft($entity, $userId);
            }

            // If a draft was removed, stop the save (nothing to save anyway)
            if ($this->draftRemoved) {
                $event->stopPropagation();
                $event->setResult(false);
            }

            return;
        }

        // Validate entity if configured
        if ($this->getConfig('validateOnDraft')) {
            $validator = $this->_table->getValidator();
            $errors = $validator->validate($entity->toArray(), $isNew);
            if ($errors) {
                $entity->setErrors($errors);
                $event->stopPropagation();
                $event->setResult(false);

                return;
            }
        }

        // Create bouncer record
        $bouncerRecord = $this->createBouncerRecord($entity, $options);

        if (!$bouncerRecord) {
            // @phpstan-ignore if.alwaysFalse (createBouncerRecord sets this property)
            if ($this->bouncerFailed) {
                // Bouncer record creation failed - block the save to prevent bypass
                $event->stopPropagation();
                $event->setResult(false);

                return;
            }

            // No bouncer record created intentionally (reverted to original, no changes)
            // Allow the save to proceed normally
            return;
        }

        $this->wasBounced = true;
        $this->lastBouncerRecord = $bouncerRecord;

        // Prevent actual save
        $event->stopPropagation();
        $event->setResult(false);
    }

    /**
     * Before delete callback.
     *
     * Intercepts the delete operation and creates a bouncer record instead.
     *
     * @param \Cake\Event\EventInterface $event The event
     * @param \Cake\Datasource\EntityInterface $entity The entity
     * @param \ArrayObject $options The options
     *
     * @return void
     */
    public function beforeDelete(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        $this->wasBounced = false;
        $this->lastBouncerRecord = null;
        $this->bouncerFailed = false;

        // Check if we should bypass bouncer
        if ($this->shouldBypass($entity, $options)) {
            return;
        }

        // Check if delete requires approval
        if (!in_array('delete', $this->getConfig('requireApproval'), true)) {
            return;
        }

        // Create bouncer record for deletion
        $bouncerRecord = $this->createDeleteBouncerRecord($entity, $options);

        if (!$bouncerRecord) {
            $event->stopPropagation();
            $event->setResult(false);

            return;
        }

        $this->wasBounced = true;
        $this->lastBouncerRecord = $bouncerRecord;

        // Prevent actual delete
        $event->stopPropagation();
        $event->setResult(false);
    }

    /**
     * Create a bouncer record for the entity.
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity
     * @param \ArrayObject $options Save options
     *
     * @return \Bouncer\Model\Entity\BouncerRecord|null
     */
    protected function createBouncerRecord(EntityInterface $entity, ArrayObject $options)
    {
        /** @var \Bouncer\Model\Table\BouncerRecordsTable $bouncerTable */
        $bouncerTable = $this->fetchTable('Bouncer.BouncerRecords');

        $userId = $this->getUserId($entity, $options);
        if (!$userId) {
            $this->bouncerFailed = true;

            return null;
        }

        $isNew = $entity->isNew();
        $primaryKeyField = $this->_table->getPrimaryKey();
        $primaryKey = $isNew ? null : $entity->get(is_array($primaryKeyField) ? $primaryKeyField[0] : $primaryKeyField);

        $source = $this->_table->getAlias();

        // Check if user already has a pending draft
        $existingDraft = $bouncerTable->findPendingForRecord(
            $source,
            $primaryKey,
            $userId,
        )->first();

        $data = $this->serializeEntity($entity);
        $originalData = null;

        $originalModified = null;
        if (!$isNew) {
            // For edits, store the current state as original
            $original = $this->_table->get($primaryKey);
            $originalData = $this->serializeEntity($original);
            // Capture modification timestamp for conflict detection
            $originalModified = $original->get('modified') ?? $original->get('created');
        }

        $note = $this->getNote($options);

        if ($existingDraft) {
            // Check if existing draft is a delete (different type)
            $existingData = json_decode($existingDraft->get('data'), true) ?: [];
            $isExistingDelete = isset($existingData['_delete']) && $existingData['_delete'] === true;

            if ($isExistingDelete) {
                // Existing draft is a delete, create new edit draft (will be superseded below)
                $bouncerData = [
                    'source' => $source,
                    'primary_key' => $primaryKey,
                    'user_id' => $userId,
                    'status' => 'pending',
                    'data' => $data,
                    'original_data' => $originalData,
                    'note' => $note,
                ];
                if ($originalModified !== null) {
                    $bouncerData['original_modified'] = $originalModified;
                }
                $bouncerRecord = $bouncerTable->newEntity($bouncerData);
                $bouncerRecord = $bouncerTable->save($bouncerRecord, ['atomic' => false]);
            } else {
                // Check if the new data matches the original data (effectively reverted)
                $proposedData = json_decode($data, true) ?: [];
                $originalDataArray = json_decode($originalData ?? '{}', true) ?: [];

                // Compare only the fields that are in the proposed data
                $isReverted = true;
                foreach ($proposedData as $field => $value) {
                    $originalValue = $originalDataArray[$field] ?? null;
                    // Use loose comparison to handle string/int mismatches
                    if ($originalValue != $value) {
                        $isReverted = false;

                        break;
                    }
                }

                if ($isReverted && count($proposedData) > 0) {
                    // Changes reverted to original - delete the pending draft
                    $bouncerTable->delete($existingDraft);
                    $this->draftRemoved = true;

                    // Commit the transaction to persist the delete
                    $connection = $bouncerTable->getConnection();
                    if ($connection->inTransaction()) {
                        $connection->commit();
                        $connection->begin();
                    }

                    $bouncerRecord = null;
                } else {
                    // Update existing edit draft
                    $patchData = [
                        'data' => $data,
                        'original_data' => $originalData,
                    ];
                    if ($note !== null) {
                        $patchData['note'] = $note;
                    }
                    if ($originalModified !== null) {
                        $patchData['original_modified'] = $originalModified;
                    }
                    $bouncerTable->patchEntity($existingDraft, $patchData);
                    $bouncerRecord = $bouncerTable->save($existingDraft, ['atomic' => false]);
                }
            }
        } else {
            // Check if the new data matches the original data (effectively no change needed)
            // Only applies to edits, not adds
            if (!$entity->isNew() && $originalData) {
                $proposedData = json_decode($data, true) ?: [];
                $originalDataArray = json_decode($originalData, true) ?: [];

                // Compare only the fields that are in the proposed data
                $isUnchanged = true;
                foreach ($proposedData as $field => $value) {
                    $originalValue = $originalDataArray[$field] ?? null;
                    // Use loose comparison to handle string/int mismatches
                    if ($originalValue != $value) {
                        $isUnchanged = false;

                        break;
                    }
                }

                if ($isUnchanged && count($proposedData) > 0) {
                    // No actual changes - don't create a draft
                    return null;
                }
            }

            // Create new draft
            $bouncerData = [
                'source' => $source,
                'primary_key' => $primaryKey,
                'user_id' => $userId,
                'status' => 'pending',
                'data' => $data,
                'original_data' => $originalData,
                'note' => $note,
            ];
            if ($originalModified !== null) {
                $bouncerData['original_modified'] = $originalModified;
            }
            $bouncerRecord = $bouncerTable->newEntity($bouncerData);
            $bouncerRecord = $bouncerTable->save($bouncerRecord, ['atomic' => false]);
        }

        if (!$bouncerRecord) {
            $this->bouncerFailed = true;

            return null;
        }

        // Supersede other pending drafts if configured
        if ($this->getConfig('autoSupersede')) {
            $bouncerTable->supersedeOthers(
                $source,
                $primaryKey,
                $bouncerRecord->id,
            );
        }

        // IMPORTANT: Commit the transaction to persist the bouncer record
        // before the parent save is rolled back.
        // We restart the transaction immediately so the parent save can still
        // roll back without affecting our bouncer record.
        $connection = $bouncerTable->getConnection();
        if ($connection->inTransaction()) {
            $connection->commit();
            // Start a new transaction for the parent save to roll back
            $connection->begin();
        }

        return $bouncerRecord;
    }

    /**
     * Create a bouncer record for entity deletion.
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity
     * @param \ArrayObject $options Delete options
     *
     * @return \Bouncer\Model\Entity\BouncerRecord|null
     */
    protected function createDeleteBouncerRecord(EntityInterface $entity, ArrayObject $options)
    {
        /** @var \Bouncer\Model\Table\BouncerRecordsTable $bouncerTable */
        $bouncerTable = $this->fetchTable('Bouncer.BouncerRecords');

        $userId = $this->getUserId($entity, $options);
        if (!$userId) {
            $this->bouncerFailed = true;

            return null;
        }

        $primaryKeyField = $this->_table->getPrimaryKey();
        $primaryKey = $entity->get(is_array($primaryKeyField) ? $primaryKeyField[0] : $primaryKeyField);
        $source = $this->_table->getAlias();

        // Check if user already has a pending draft for this record
        $existingDraft = $bouncerTable->findPendingForRecord(
            $source,
            $primaryKey,
            $userId,
        )->first();

        // Store current entity state as original_data
        $originalData = json_encode($entity->toArray());
        $data = json_encode(['_delete' => true]); // Mark as deletion
        $note = $this->getNote($options);
        // Capture modification timestamp for conflict detection
        $originalModified = $entity->get('modified') ?? $entity->get('created');

        if ($existingDraft) {
            // Check if existing draft is also a delete (same type)
            $existingData = json_decode($existingDraft->get('data'), true) ?: [];
            $isExistingDelete = isset($existingData['_delete']) && $existingData['_delete'] === true;

            if ($isExistingDelete) {
                // Update existing delete draft
                $patchData = [
                    'data' => $data,
                    'original_data' => $originalData,
                ];
                if ($note !== null) {
                    $patchData['note'] = $note;
                }
                if ($originalModified !== null) {
                    $patchData['original_modified'] = $originalModified;
                }
                $bouncerTable->patchEntity($existingDraft, $patchData);
                $bouncerRecord = $bouncerTable->save($existingDraft, ['atomic' => false]);
            } else {
                // Existing draft is an edit, create new delete draft (will be superseded below)
                $bouncerData = [
                    'source' => $source,
                    'primary_key' => $primaryKey,
                    'user_id' => $userId,
                    'status' => 'pending',
                    'data' => $data,
                    'original_data' => $originalData,
                    'note' => $note,
                ];
                if ($originalModified !== null) {
                    $bouncerData['original_modified'] = $originalModified;
                }
                $bouncerRecord = $bouncerTable->newEntity($bouncerData);
                $bouncerRecord = $bouncerTable->save($bouncerRecord, ['atomic' => false]);
            }
        } else {
            // Create new delete bouncer record
            $bouncerData = [
                'source' => $source,
                'primary_key' => $primaryKey,
                'user_id' => $userId,
                'status' => 'pending',
                'data' => $data,
                'original_data' => $originalData,
                'note' => $note,
            ];
            if ($originalModified !== null) {
                $bouncerData['original_modified'] = $originalModified;
            }
            $bouncerRecord = $bouncerTable->newEntity($bouncerData);
            $bouncerRecord = $bouncerTable->save($bouncerRecord, ['atomic' => false]);
        }

        if (!$bouncerRecord) {
            $this->bouncerFailed = true;

            return null;
        }

        // Supersede other pending drafts if configured
        if ($this->getConfig('autoSupersede')) {
            $bouncerTable->supersedeOthers(
                $source,
                $primaryKey,
                $bouncerRecord->id,
            );
        }

        // Commit the transaction to persist the bouncer record
        $connection = $bouncerTable->getConnection();
        if ($connection->inTransaction()) {
            $connection->commit();
            $connection->begin();
        }

        return $bouncerRecord;
    }

    /**
     * Serialize entity to JSON string.
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity
     *
     * @throws \RuntimeException
     *
     * @return string
     */
    protected function serializeEntity(EntityInterface $entity): string
    {
        // Get only dirty fields for updates, all fields for new records
        // BUT: if no dirty fields (e.g. freshly loaded entity for original_data), get all fields
        if ($entity->isNew()) {
            $data = $entity->toArray();
        } else {
            $dirty = $entity->getDirty();
            if (!$dirty) {
                // No dirty fields - this is likely original_data, get all fields
                $data = $entity->toArray();
            } else {
                $data = $entity->extract($dirty);
            }
        }

        // Remove internal fields
        unset($data['created'], $data['modified']);

        $encoded = json_encode($data);
        if ($encoded === false) {
            throw new RuntimeException('Failed to encode entity data');
        }

        return $encoded;
    }

    /**
     * Get user ID from entity or options.
     *
     * Supports both integer and string (UUID) user IDs.
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @param \ArrayObject $options Options
     *
     * @return string|int|null
     */
    protected function getUserId(EntityInterface $entity, ArrayObject $options): int|string|null
    {
        $userField = $this->getConfig('userField');

        // Check options first
        if (isset($options['bouncerUserId'])) {
            return $options['bouncerUserId'];
        }

        // Check entity
        if ($entity->has($userField)) {
            return $entity->get($userField);
        }

        return null;
    }

    /**
     * Get note from options.
     *
     * @param \ArrayObject $options Options
     *
     * @return string|null
     */
    protected function getNote(ArrayObject $options): ?string
    {
        if (isset($options['bouncerNote'])) {
            $note = (string)$options['bouncerNote'];
            // Truncate to max 255 chars
            if (mb_strlen($note) > 255) {
                $note = mb_substr($note, 0, 255);
            }

            return $note;
        }

        return null;
    }

    /**
     * Check if we should bypass the bouncer.
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @param \ArrayObject $options Options
     *
     * @return bool
     */
    protected function shouldBypass(EntityInterface $entity, ArrayObject $options): bool
    {
        // Check if explicitly bypassed
        if (!empty($options['bypassBouncer'])) {
            return true;
        }

        // Check bypass callback if configured
        $callback = $this->getConfig('bypassCallback');
        if ($callback !== null && is_callable($callback)) {
            $result = $callback($entity, $options, $this->_table);
            if ($result === true) {
                return true;
            }
        }

        // Check exempt users (fallback for backward compatibility)
        $userId = $this->getUserId($entity, $options);
        if ($userId && in_array($userId, $this->getConfig('exemptUsers'), true)) {
            return true;
        }

        return false;
    }

    /**
     * Check if the last save was bounced.
     *
     * @return bool
     */
    public function wasBounced(): bool
    {
        return $this->wasBounced;
    }

    /**
     * Get the last bouncer record created.
     *
     * @return \Bouncer\Model\Entity\BouncerRecord|null
     */
    public function getLastBouncerRecord()
    {
        return $this->lastBouncerRecord;
    }

    /**
     * Check if the last save operation resulted in a draft being removed.
     *
     * This happens when the user reverts their changes back to the original content,
     * effectively cancelling their proposal.
     *
     * @return bool
     */
    public function wasDraftRemoved(): bool
    {
        return $this->draftRemoved;
    }

    /**
     * Remove a pending draft if the entity has reverted to original values.
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity being saved
     * @param string|int $userId The user ID (int or UUID string)
     *
     * @return void
     */
    protected function removeRevertedDraft(EntityInterface $entity, int|string $userId): void
    {
        $primaryKeyField = $this->_table->getPrimaryKey();
        $primaryKey = $entity->get(is_array($primaryKeyField) ? $primaryKeyField[0] : $primaryKeyField);

        if (!$primaryKey) {
            return;
        }

        /** @var \Bouncer\Model\Table\BouncerRecordsTable $bouncerTable */
        $bouncerTable = $this->fetchTable('Bouncer.BouncerRecords');

        $existingDraft = $bouncerTable->findPendingForRecord(
            $this->_table->getAlias(),
            $primaryKey,
            $userId,
        )->first();

        if ($existingDraft) {
            // Draft exists and entity has no changes - remove the draft
            $bouncerTable->delete($existingDraft);
            $this->draftRemoved = true;
        }
    }

    /**
     * Load a pending draft for the given primary key and user.
     *
     * Supports both integer and string (UUID) primary keys and user IDs.
     *
     * @param string|int $primaryKey Primary key (int or UUID)
     * @param string|int $userId User ID (int or UUID)
     *
     * @return \Bouncer\Model\Entity\BouncerRecord|null
     */
    public function loadDraft(int|string $primaryKey, int|string $userId): ?BouncerRecord
    {
        /** @var \Bouncer\Model\Table\BouncerRecordsTable $bouncerTable */
        $bouncerTable = $this->fetchTable('Bouncer.BouncerRecords');

        return $bouncerTable->findPendingForRecord(
            $this->_table->getAlias(),
            $primaryKey,
            $userId,
        )->first();
    }

    /**
     * Check if a user has a pending draft for a record.
     *
     * Supports both integer and string (UUID) primary keys and user IDs.
     *
     * @param string|int|null $primaryKey Primary key (int or UUID) or null for new records
     * @param string|int $userId User ID (int or UUID)
     *
     * @return bool
     */
    public function hasPendingDraft(int|string|null $primaryKey, int|string $userId): bool
    {
        if ($primaryKey === null) {
            return false;
        }

        /** @var \Bouncer\Model\Table\BouncerRecordsTable $bouncerTable */
        $bouncerTable = $this->fetchTable('Bouncer.BouncerRecords');

        return $bouncerTable->findPendingForRecord(
            $this->_table->getAlias(),
            $primaryKey,
            $userId,
        )->count() > 0;
    }

    /**
     * Load draft and overlay it on the entity if one exists.
     *
     * Convenience method that loads a pending draft for the given user
     * and overlays the draft data onto the entity for display/editing.
     *
     * Returns the draft entity if found and applied, allowing access to
     * original data via `$draft->getOriginalData()` for comparison/diff views.
     *
     * Supports both integer and string (UUID) user IDs.
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity to overlay draft on
     * @param string|int $userId User ID (int or UUID)
     *
     * @return \Bouncer\Model\Entity\BouncerRecord|null The draft entity if found and applied, null otherwise
     */
    public function withDraft(EntityInterface $entity, int|string $userId): ?BouncerRecord
    {
        $primaryKeyField = $this->_table->getPrimaryKey();
        $primaryKey = $entity->get(is_array($primaryKeyField) ? $primaryKeyField[0] : $primaryKeyField);

        if (!$primaryKey) {
            return null;
        }

        $draft = $this->loadDraft($primaryKey, $userId);

        if (!$draft) {
            return null;
        }

        // Overlay draft data onto the entity
        $draftData = json_decode($draft->get('data'), true) ?: [];

        // Don't overlay delete drafts - they only contain {_delete: true}
        if (isset($draftData['_delete']) && $draftData['_delete'] === true) {
            return null;
        }

        // Include the primary key in draft data to signal to beforeMarshal
        // that this is an existing entity, not a new one
        $primaryKeyField = $this->_table->getPrimaryKey();
        if (is_string($primaryKeyField)) {
            $draftData[$primaryKeyField] = $primaryKey;
        }

        $this->_table->patchEntity($entity, $draftData);

        return $draft;
    }

    /**
     * Apply approved bouncer record changes to the actual table.
     *
     * @param \Bouncer\Model\Entity\BouncerRecord $bouncerRecord Bouncer record
     * @param array<string, mixed> $options Save options
     *
     * @return \Cake\Datasource\EntityInterface|bool
     */
    public function applyApprovedChanges($bouncerRecord, array $options = [])
    {
        // Use merged data if available (for 3-way merge scenarios), otherwise use original data
        $data = $bouncerRecord->getMergedData();

        // Check if this is a delete operation
        if (isset($data['_delete']) && $data['_delete']) {
            // This is a delete operation
            $entity = $this->_table->get($bouncerRecord->primary_key);
            $options['bypassBouncer'] = true;

            return $this->_table->delete($entity, $options);
        }

        if ($bouncerRecord->isNewRecordProposal()) {
            // Create new entity
            $entity = $this->_table->newEntity($data);
        } else {
            // Load and patch existing entity
            $entity = $this->_table->get($bouncerRecord->primary_key);
            $entity = $this->_table->patchEntity($entity, $data);
        }

        // Bypass bouncer for this save
        $options['bypassBouncer'] = true;

        return $this->_table->save($entity, $options);
    }
}
