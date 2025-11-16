<?php

declare(strict_types=1);

namespace Bouncer\Model\Behavior;

use ArrayObject;
use Bouncer\Model\Table\BouncerRecordsTable;
use Cake\Datasource\EntityInterface;
use Cake\Event\EventInterface;
use Cake\ORM\Behavior;
use Cake\ORM\Locator\LocatorAwareTrait;
use Cake\Utility\Hash;

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
        'requireApproval' => ['add', 'edit'],
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
    protected $lastBouncerRecord = null;

    /**
     * Initialize hook.
     *
     * @param array<string, mixed> $config Configuration
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
     * @return bool
     */
    public function beforeSave(EventInterface $event, EntityInterface $entity, ArrayObject $options): bool
    {
        $this->wasBounced = false;
        $this->lastBouncerRecord = null;

        // Check if we should bypass bouncer
        if ($this->shouldBypass($entity, $options)) {
            return true;
        }

        // Check if this action requires approval
        $isNew = $entity->isNew();
        $action = $isNew ? 'add' : 'edit';

        if (!in_array($action, $this->getConfig('requireApproval'), true)) {
            return true;
        }

        // Validate entity if configured
        if ($this->getConfig('validateOnDraft')) {
            $validator = $this->_table->getValidator();
            $errors = $validator->validate($entity->toArray(), $isNew);
            if ($errors) {
                $entity->setErrors($errors);

                return false;
            }
        }

        // Create bouncer record
        $bouncerRecord = $this->createBouncerRecord($entity, $options);

        if (!$bouncerRecord) {
            return false;
        }

        $this->wasBounced = true;
        $this->lastBouncerRecord = $bouncerRecord;

        // Prevent actual save
        $event->stopPropagation();

        return false;
    }

    /**
     * Create a bouncer record for the entity.
     *
     * @param \Cake\Datasource\EntityInterface $entity The entity
     * @param \ArrayObject $options Save options
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
        $primaryKey = $isNew ? null : $entity->get($this->_table->getPrimaryKey());

        // Check if user already has a pending draft
        $existingDraft = $bouncerTable->findPendingForRecord(
            $this->_table->getTable(),
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
            // Update existing draft
            $bouncerTable->patchEntity($existingDraft, [
                'data' => $data,
                'original_data' => $originalData,
            ]);
            $bouncerRecord = $bouncerTable->save($existingDraft);
        } else {
            // Create new draft
            $bouncerRecord = $bouncerTable->newEntity([
                'source' => $this->_table->getTable(),
                'primary_key' => $primaryKey,
                'user_id' => $userId,
                'status' => 'pending',
                'data' => $data,
                'original_data' => $originalData,
            ]);
            $bouncerRecord = $bouncerTable->save($bouncerRecord);
        }

        // Supersede other pending drafts if configured
        if ($bouncerRecord && $this->getConfig('autoSupersede')) {
            $bouncerTable->supersedeOthers(
                $this->_table->getTable(),
                $primaryKey,
                $bouncerRecord->id,
            );
        }

        return $bouncerRecord;
    }

    /**
     * Serialize entity to JSON string.
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @return string
     */
    protected function serializeEntity(EntityInterface $entity): string
    {
        // Get only dirty fields for updates, all fields for new records
        $data = $entity->isNew() ? $entity->toArray() : $entity->extractOriginalChanged($entity->getDirty());

        // Remove internal fields
        unset($data['created'], $data['modified']);

        return json_encode($data);
    }

    /**
     * Get user ID from entity or options.
     *
     * @param \Cake\Datasource\EntityInterface $entity Entity
     * @param \ArrayObject $options Options
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
     * @return \Bouncer\Model\Entity\BouncerRecord|null
     */
    public function loadDraft(int $primaryKey, int $userId)
    {
        /** @var \Bouncer\Model\Table\BouncerRecordsTable $bouncerTable */
        $bouncerTable = $this->fetchTable('Bouncer.BouncerRecords');

        return $bouncerTable->findPendingForRecord(
            $this->_table->getTable(),
            $primaryKey,
            $userId,
        )->first();
    }

    /**
     * Check if a user has a pending draft for a record.
     *
     * @param int|null $primaryKey Primary key or null for new records
     * @param int $userId User ID
     * @return bool
     */
    public function hasPendingDraft(?int $primaryKey, int $userId): bool
    {
        /** @var \Bouncer\Model\Table\BouncerRecordsTable $bouncerTable */
        $bouncerTable = $this->fetchTable('Bouncer.BouncerRecords');

        return $bouncerTable->findPendingForRecord(
            $this->_table->getTable(),
            $primaryKey,
            $userId,
        )->count() > 0;
    }

    /**
     * Apply approved bouncer record changes to the actual table.
     *
     * @param \Bouncer\Model\Entity\BouncerRecord $bouncerRecord Bouncer record
     * @param array<string, mixed> $options Save options
     * @return \Cake\Datasource\EntityInterface|false
     */
    public function applyApprovedChanges($bouncerRecord, array $options = [])
    {
        $data = $bouncerRecord->getData();

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
