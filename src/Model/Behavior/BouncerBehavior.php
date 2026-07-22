<?php

declare(strict_types=1);

namespace Bouncer\Model\Behavior;

use ArrayObject;
use Bouncer\Lib\ThreeWayMerge;
use Bouncer\Model\Entity\BouncerRecord;
use Cake\Database\Connection;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\Log\Log;
use Cake\ORM\Behavior;
use Cake\ORM\Locator\LocatorAwareTrait;
use ReflectionObject;
use RuntimeException;
use Throwable;

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
     * Default JSON encoding flags for storing data.
     *
     * - JSON_UNESCAPED_UNICODE: Store UTF-8 characters directly (ö instead of \u00f6)
     * - JSON_UNESCAPED_SLASHES: Don't escape forward slashes
     * - JSON_PRESERVE_ZERO_FRACTION: Keep 10.0 as 10.0 instead of 10
     *
     * @var int
     */
    public const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;

    /**
     * Default configuration.
     *
     * @var array<string, mixed>
     */
    protected array $_defaultConfig = [
        'userField' => 'user_id',
        'requireApproval' => ['add', 'edit', 'delete'],
        'exemptUsers' => [],
        'bypassCallback' => null,
        'validateOnDraft' => true,
        'autoSupersede' => true,
        'cleanupOnDelete' => true,
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
     * Persist the bouncer record by committing the current transaction and reopening a new one,
     * so the bouncer record survives the parent save's rollback.
     *
     * If the host application has wrapped the save in its own outer transaction (i.e. the
     * connection's transaction nesting level is greater than 1), force-committing here would
     * silently close the host's transaction and corrupt its data integrity guarantees. In that
     * case we skip the force-commit and log a warning so consumers can detect the unsupported
     * scenario. The bouncer record will still be persisted as part of the host transaction; if
     * the host then rolls back, the bouncer record is rolled back with it (acceptable trade-off
     * vs. corrupting the host's data).
     *
     * Normalize a value for the revert / unchanged comparison.
     *
     * JSON-encode so the comparison preserves type distinctions PHP's loose
     * `==` would collapse — `'0' == false`, `'' == null`, `'abc' == 0` —
     * while leaving same-type values (string `"foo"` vs string `"foo"`)
     * trivially equal. The JSON shape also captures arrays and nested
     * structures consistently.
     *
     * @param mixed $value
     *
     * @return string
     */
    protected function normalizeForCompare(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '';
    }

    /**
     * @param \Cake\Database\Connection $connection Connection
     *
     * @return void
     */
    protected function commitBouncerRecord(Connection $connection): void
    {
        if (!$connection->inTransaction()) {
            return;
        }

        if ($this->isHostTransactionWrapped($connection)) {
            Log::warning(
                'Bouncer: skipping force-commit because save() is wrapped in an outer host '
                . 'transaction. The bouncer record will participate in the host transaction '
                . 'and will be rolled back if the host rolls back. To preserve bouncer records '
                . 'across host rollbacks, do not wrap Bouncer-enabled save() calls inside your '
                . 'own transactional() block.',
                ['scope' => ['bouncer']],
            );

            return;
        }

        $connection->commit();
        // Restart a fresh transaction so the parent save can still roll back without affecting
        // the now-persisted bouncer record.
        $connection->begin();
    }

    /**
     * Property names CakePHP's `Connection` has used (and may use) for the
     * current transaction-nesting depth. Probed in order so we keep working
     * if/when cake-core renames `_transactionLevel` (the underscore prefix is
     * a legacy 4.x convention that the project is gradually shedding).
     *
     * @var list<string>
     */
    protected const TRANSACTION_LEVEL_CANDIDATES = ['_transactionLevel', 'transactionLevel'];

    /**
     * Detect whether the connection's current transaction was opened by the host application
     * (i.e. the nesting level is greater than 1, meaning more than just the ORM save's own
     * transaction is on the stack).
     *
     * Reads the protected transaction-level property via reflection because CakePHP's
     * Connection does not expose it through a public accessor. Tries multiple candidate
     * property names (see {@see self::TRANSACTION_LEVEL_CANDIDATES}) so a rename in a
     * future cake-core release does not silently flip the behavior into the unsafe branch.
     * If introspection genuinely fails, the method returns `true` — that's the safe
     * direction (skip force-commit, let the bouncer record participate in the host
     * transaction) rather than the dangerous direction (force-commit and corrupt
     * the host's transaction).
     *
     * @param \Cake\Database\Connection $connection Connection
     *
     * @return bool
     */
    protected function isHostTransactionWrapped(Connection $connection): bool
    {
        $reflection = new ReflectionObject($connection);
        foreach (static::TRANSACTION_LEVEL_CANDIDATES as $candidate) {
            if (!$reflection->hasProperty($candidate)) {
                continue;
            }
            try {
                $level = $reflection->getProperty($candidate)->getValue($connection);
            } catch (Throwable) {
                continue;
            }
            if (!is_int($level)) {
                continue;
            }

            // Level 1 means only the ORM save() opened the transaction (the expected case).
            // Level >= 2 means the host wrapped the save in its own transactional() block.
            return $level > 1;
        }

        // Could not introspect transaction depth on any known property layout.
        // Be conservative: treat as host-wrapped so we DO NOT force-commit on
        // top of a host transaction. Bouncer records will roll back with the
        // host if the host rolls back, which is the lesser of two evils.
        Log::warning(
            'Bouncer: unable to detect transaction nesting depth on the connection '
            . '(no known transaction-level property found). Assuming the host '
            . 'transaction wraps the save and skipping force-commit; bouncer '
            . 'records will participate in the host transaction. If your CakePHP '
            . 'version exposes transaction depth under a new property name, extend '
            . 'BouncerBehavior::TRANSACTION_LEVEL_CANDIDATES accordingly.',
            ['scope' => ['bouncer']],
        );

        return true;
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
     * Clean up bouncer records for a source row once it is actually deleted.
     *
     * Bouncer records reference their source only by `source` + `primary_key`,
     * with no database-level foreign key or cascade, so a deleted source row
     * would otherwise leave orphaned proposals and history in the review queue
     * pointing at a record that no longer exists (and, for the source app, links
     * that 404).
     *
     * Only fires on a real delete: when `delete` requires approval,
     * `beforeDelete()` stops propagation and creates a delete proposal instead,
     * so this never runs in that path and cannot wipe the just-created proposal.
     * Disable via the `cleanupOnDelete` config when records must outlive their
     * source (e.g. an audit trail).
     *
     * @param \Cake\Event\EventInterface<\Cake\ORM\Table> $event
     * @param \Cake\Datasource\EntityInterface $entity
     * @param \ArrayObject<string, mixed> $options
     *
     * @return void
     */
    public function afterDelete(EventInterface $event, EntityInterface $entity, ArrayObject $options): void
    {
        if (!$this->getConfig('cleanupOnDelete')) {
            return;
        }

        $primaryKeyField = $this->_table->getPrimaryKey();
        $primaryKey = $entity->get(is_array($primaryKeyField) ? $primaryKeyField[0] : $primaryKeyField);
        if ($primaryKey === null) {
            return;
        }

        /** @var \Bouncer\Model\Table\BouncerRecordsTable $bouncerTable */
        $bouncerTable = $this->fetchTable('Bouncer.BouncerRecords');
        $bouncerTable->deleteAll([
            'source' => $this->_table->getRegistryAlias(),
            'primary_key' => (string)$primaryKey,
        ]);
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

        $source = $this->_table->getRegistryAlias();

        // Check if user already has a pending draft
        /** @var \Bouncer\Model\Entity\BouncerRecord|null $existingDraft */
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
            /** @var \Cake\Datasource\EntityInterface $original */
            $original = $this->_table->get($primaryKey);
            $originalData = $this->serializeEntity($original);
            // Capture modification timestamp for conflict detection
            $originalModified = $original->get('modified') ?? $original->get('created');
        }

        $note = $this->getNote($options);
        $userDisplay = $this->getUserDisplay($options);

        if ($existingDraft) {
            // Check if existing draft is a delete (different type)
            $existingData = json_decode((string)$existingDraft->get('data'), true) ?: [];
            $isExistingDelete = isset($existingData['_delete']) && $existingData['_delete'] === true;

            if ($isExistingDelete) {
                // Existing draft is a delete, create new edit draft (will be superseded below)
                $bouncerData = [
                    'source' => $source,
                    'primary_key' => $primaryKey,
                    'user_id' => $userId,
                    'user_display' => $userDisplay,
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

                // Compare only the fields that are in the proposed data.
                // Both sides are normalized to string before strict comparison
                // so a numeric column round-tripped through JSON (string vs int)
                // doesn't read as "changed". Loose `!=` here used to coerce
                // `'0' == false` and similar nulls — that misclassified real
                // user edits as reverts and silently dropped the draft.
                $isReverted = true;
                foreach ($proposedData as $field => $value) {
                    $originalValue = $originalDataArray[$field] ?? null;
                    if ($this->normalizeForCompare($originalValue) !== $this->normalizeForCompare($value)) {
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
                    $this->commitBouncerRecord($connection);

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

                // Same normalization as the revert-detection branch above.
                $isUnchanged = true;
                foreach ($proposedData as $field => $value) {
                    $originalValue = $originalDataArray[$field] ?? null;
                    if ($this->normalizeForCompare($originalValue) !== $this->normalizeForCompare($value)) {
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
                'user_display' => $userDisplay,
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
            // Only mark as failed if the draft wasn't intentionally removed (reverted case)
            if (!$this->draftRemoved) {
                $this->bouncerFailed = true;
            }

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
        $this->commitBouncerRecord($connection);

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
        $source = $this->_table->getRegistryAlias();

        // Check if user already has a pending draft for this record
        /** @var \Bouncer\Model\Entity\BouncerRecord|null $existingDraft */
        $existingDraft = $bouncerTable->findPendingForRecord(
            $source,
            $primaryKey,
            $userId,
        )->first();

        // Store current entity state as original_data
        $originalData = json_encode($entity->toArray(), self::JSON_FLAGS);
        $data = json_encode(['_delete' => true], self::JSON_FLAGS); // Mark as deletion
        $note = $this->getNote($options);
        $userDisplay = $this->getUserDisplay($options);
        // Capture modification timestamp for conflict detection
        $originalModified = $entity->get('modified') ?? $entity->get('created');

        if ($existingDraft) {
            // Check if existing draft is also a delete (same type)
            $existingData = json_decode((string)$existingDraft->get('data'), true) ?: [];
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
                    'user_display' => $userDisplay,
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
                'user_display' => $userDisplay,
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
        $this->commitBouncerRecord($connection);

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

        $encoded = json_encode($data, self::JSON_FLAGS);
        if ($encoded === false) {
            throw new RuntimeException('Failed to encode entity data');
        }

        return $encoded;
    }

    /**
     * Get user ID from entity or options.
     *
     * Supports both integer and string (UUID) user IDs.
     * Also parses compound format "id:displayName" if present.
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @param \ArrayObject $options Options
     *
     * @return string|int|null
     */
    protected function getUserId(EntityInterface $entity, ArrayObject $options): string|int|null
    {
        $userField = $this->getConfig('userField');

        // Check options first
        if (isset($options['bouncerUserId'])) {
            $userId = $options['bouncerUserId'];
            // Parse compound format "id:displayName"
            $userIdStr = (string)$userId;
            if (str_contains($userIdStr, ':')) {
                $parts = explode(':', $userIdStr, 2);

                return $parts[0]; // Return just the ID part
            }

            return $userId;
        }

        // Check entity
        if ($entity->has($userField)) {
            return $entity->get($userField);
        }

        return null;
    }

    /**
     * Get user display name from options.
     *
     * Parses compound format "id:displayName" if present in bouncerUserId.
     *
     * @param \ArrayObject $options Options
     *
     * @return string|null
     */
    protected function getUserDisplay(ArrayObject $options): ?string
    {
        if (isset($options['bouncerUserDisplay'])) {
            return (string)$options['bouncerUserDisplay'];
        }

        // Parse from compound format in bouncerUserId
        if (isset($options['bouncerUserId'])) {
            $userIdStr = (string)$options['bouncerUserId'];
            if (str_contains($userIdStr, ':')) {
                $parts = explode(':', $userIdStr, 2);

                return $parts[1] ?? null;
            }
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

        return $userId && in_array($userId, $this->getConfig('exemptUsers'), true);
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
    protected function removeRevertedDraft(EntityInterface $entity, string|int $userId): void
    {
        $primaryKeyField = $this->_table->getPrimaryKey();
        $primaryKey = $entity->get(is_array($primaryKeyField) ? $primaryKeyField[0] : $primaryKeyField);

        if (!$primaryKey) {
            return;
        }

        /** @var \Bouncer\Model\Table\BouncerRecordsTable $bouncerTable */
        $bouncerTable = $this->fetchTable('Bouncer.BouncerRecords');

        /** @var \Bouncer\Model\Entity\BouncerRecord|null $existingDraft */
        $existingDraft = $bouncerTable->findPendingForRecord(
            $this->_table->getRegistryAlias(),
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
    public function loadDraft(string|int $primaryKey, string|int $userId): ?BouncerRecord
    {
        /** @var \Bouncer\Model\Table\BouncerRecordsTable $bouncerTable */
        $bouncerTable = $this->fetchTable('Bouncer.BouncerRecords');

        /** @var \Bouncer\Model\Entity\BouncerRecord|null $draft */
        $draft = $bouncerTable->findPendingForRecord(
            $this->_table->getRegistryAlias(),
            $primaryKey,
            $userId,
        )->first();

        return $draft;
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
    public function hasPendingDraft(string|int|null $primaryKey, string|int $userId): bool
    {
        if ($primaryKey === null) {
            return false;
        }

        /** @var \Bouncer\Model\Table\BouncerRecordsTable $bouncerTable */
        $bouncerTable = $this->fetchTable('Bouncer.BouncerRecords');

        return $bouncerTable->findPendingForRecord(
            $this->_table->getRegistryAlias(),
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
    public function withDraft(EntityInterface $entity, string|int $userId): ?BouncerRecord
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
     * For edit proposals on stale records (where the source record has been modified
     * since the proposal was created), this method automatically performs a 3-way merge
     * to preserve both the owner's changes and the proposal's changes when possible.
     *
     * Options:
     * - `autoMerge`: bool (default: true) - Whether to automatically perform 3-way merge for stale records.
     *   Set to false if you want to handle merging manually before calling this method.
     * - `skipFields`: array - Fields to skip during merge (default: ['id', 'created', 'modified', '_delete'])
     *
     * @param \Bouncer\Model\Entity\BouncerRecord $bouncerRecord Bouncer record
     * @param array<string, mixed> $options Save options
     *
     * @return \Cake\Datasource\EntityInterface|bool
     */
    public function applyApprovedChanges($bouncerRecord, array $options = [])
    {
        $autoMerge = $options['autoMerge'] ?? true;
        unset($options['autoMerge']);

        // Auto-merge stale edit proposals if not already merged
        if (
            $autoMerge
            && !$bouncerRecord->hasMergedData()
            && $bouncerRecord->isEditProposal()
            && $bouncerRecord->canDetectStaleness()
        ) {
            /** @var \Cake\Datasource\EntityInterface $currentRecord */
            $currentRecord = $this->_table->get($bouncerRecord->primary_key);
            $currentModified = $currentRecord->get('modified') ?? $currentRecord->get('created');

            if ($currentModified && $currentModified > $bouncerRecord->original_modified) {
                // Record is stale - perform 3-way merge
                $skipFields = $options['skipFields'] ?? ['id', 'created', 'modified', '_delete'];
                unset($options['skipFields']);

                $merger = new ThreeWayMerge();
                $mergeResult = $merger->mergeArrays(
                    $bouncerRecord->getOriginalData(),
                    $currentRecord->toArray(),
                    $bouncerRecord->getData(),
                    $skipFields,
                );

                // Apply merged data (even if there are conflicts, we use the merged result
                // which defaults to proposed values for conflicting fields)
                $bouncerRecord->setMergedData($mergeResult['merged']);
            }
        }

        // Use merged data if available (for 3-way merge scenarios), otherwise use original data
        $data = $bouncerRecord->getMergedData();

        // Check if this is a delete operation
        if (isset($data['_delete']) && $data['_delete']) {
            // This is a delete operation
            /** @var \Cake\Datasource\EntityInterface $entity */
            $entity = $this->_table->get($bouncerRecord->primary_key);
            $options['bypassBouncer'] = true;

            return $this->_table->delete($entity, $options);
        }

        if ($bouncerRecord->isNewRecordProposal()) {
            // Create new entity
            $entity = $this->_table->newEntity($data);
        } else {
            // Load and patch existing entity
            /** @var \Cake\Datasource\EntityInterface $entity */
            $entity = $this->_table->get($bouncerRecord->primary_key);
            $entity = $this->_table->patchEntity($entity, $data);
        }

        // Bypass bouncer for this save
        $options['bypassBouncer'] = true;

        return $this->_table->save($entity, $options);
    }
}
