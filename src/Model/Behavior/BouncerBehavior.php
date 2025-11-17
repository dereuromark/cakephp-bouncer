<?php

declare(strict_types=1);

namespace Bouncer\Model\Behavior;

use ArrayObject;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\ORM\Behavior;
use Cake\ORM\Locator\LocatorAwareTrait;

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
            $event->stopPropagation();
            $event->setResult(false);

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

        if (!$isNew) {
            // For edits, store the current state as original
            $original = $this->_table->get($primaryKey);
            $originalData = $this->serializeEntity($original);
        }

        if ($existingDraft) {
            // Check if existing draft is a delete (different type)
            $existingData = json_decode($existingDraft->get('data'), true) ?: [];
            $isExistingDelete = isset($existingData['_delete']) && $existingData['_delete'] === true;

            if ($isExistingDelete) {
                // Existing draft is a delete, create new edit draft (will be superseded below)
                $bouncerRecord = $bouncerTable->newEntity([
                    'source' => $source,
                    'primary_key' => $primaryKey,
                    'user_id' => $userId,
                    'status' => 'pending',
                    'data' => $data,
                    'original_data' => $originalData,
                ]);
                $bouncerRecord = $bouncerTable->save($bouncerRecord, ['atomic' => false]);
            } else {
                // Update existing edit draft
                $bouncerTable->patchEntity($existingDraft, [
                    'data' => $data,
                    'original_data' => $originalData,
                ]);
                $bouncerRecord = $bouncerTable->save($existingDraft, ['atomic' => false]);
            }
        } else {
            // Create new draft
            $bouncerRecord = $bouncerTable->newEntity([
                'source' => $source,
                'primary_key' => $primaryKey,
                'user_id' => $userId,
                'status' => 'pending',
                'data' => $data,
                'original_data' => $originalData,
            ]);
            $bouncerRecord = $bouncerTable->save($bouncerRecord, ['atomic' => false]);
        }

        if (!$bouncerRecord) {
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

        if ($existingDraft) {
            // Check if existing draft is also a delete (same type)
            $existingData = json_decode($existingDraft->get('data'), true) ?: [];
            $isExistingDelete = isset($existingData['_delete']) && $existingData['_delete'] === true;

            if ($isExistingDelete) {
                // Update existing delete draft
                $bouncerTable->patchEntity($existingDraft, [
                    'data' => $data,
                    'original_data' => $originalData,
                ]);
                $bouncerRecord = $bouncerTable->save($existingDraft, ['atomic' => false]);
            } else {
                // Existing draft is an edit, create new delete draft (will be superseded below)
                $bouncerRecord = $bouncerTable->newEntity([
                    'source' => $source,
                    'primary_key' => $primaryKey,
                    'user_id' => $userId,
                    'status' => 'pending',
                    'data' => $data,
                    'original_data' => $originalData,
                ]);
                $bouncerRecord = $bouncerTable->save($bouncerRecord, ['atomic' => false]);
            }
        } else {
            // Create new delete bouncer record
            $bouncerRecord = $bouncerTable->newEntity([
                'source' => $source,
                'primary_key' => $primaryKey,
                'user_id' => $userId,
                'status' => 'pending',
                'data' => $data,
                'original_data' => $originalData,
            ]);
            $bouncerRecord = $bouncerTable->save($bouncerRecord, ['atomic' => false]);
        }

        if (!$bouncerRecord) {
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
            throw new \RuntimeException('Failed to encode entity data');
        }

        return $encoded;
    }

    /**
     * Get user ID from entity or options.
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @param \ArrayObject $options Options
     *
     * @return int|null
     */
    protected function getUserId(EntityInterface $entity, ArrayObject $options): ?int
    {
        $userField = $this->getConfig('userField');

        // Check options first
        if (isset($options['bouncerUserId'])) {
            return (int)$options['bouncerUserId'];
        }

        // Check entity
        if ($entity->has($userField)) {
            return (int)$entity->get($userField);
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

        // Check exempt users
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
     * Load a pending draft for the given primary key and user.
     *
     * @param int $primaryKey Primary key
     * @param int $userId User ID
     *
     * @return \Bouncer\Model\Entity\BouncerRecord|null
     */
    public function loadDraft(int $primaryKey, int $userId)
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
     * @param int|null $primaryKey Primary key or null for new records
     * @param int $userId User ID
     *
     * @return bool
     */
    public function hasPendingDraft(?int $primaryKey, int $userId): bool
    {
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
     * @param \Cake\Datasource\EntityInterface $entity Entity to overlay draft on
     * @param int $userId User ID
     *
     * @return bool True if draft was found and applied, false otherwise
     */
    public function withDraft(EntityInterface $entity, int $userId): bool
    {
        $primaryKeyField = $this->_table->getPrimaryKey();
        $primaryKey = $entity->get(is_array($primaryKeyField) ? $primaryKeyField[0] : $primaryKeyField);

        if (!$primaryKey) {
            return false;
        }

        $draft = $this->loadDraft((int)$primaryKey, $userId);

        if (!$draft) {
            return false;
        }

        // Overlay draft data onto the entity
        $draftData = json_decode($draft->get('data'), true) ?: [];

        // Don't overlay delete drafts - they only contain {_delete: true}
        if (isset($draftData['_delete']) && $draftData['_delete'] === true) {
            return false;
        }

        $this->_table->patchEntity($entity, $draftData);

        return true;
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
        $data = $bouncerRecord->getData();

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
