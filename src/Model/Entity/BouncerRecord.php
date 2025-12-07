<?php

declare(strict_types=1);

namespace Bouncer\Model\Entity;

use Bouncer\Lib\ThreeWayMerge;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Entity;

/**
 * BouncerRecord Entity
 *
 * @property int $id
 * @property string $source
 * @property int|string|null $primary_key
 * @property int|string $user_id
 * @property string|null $user_display
 * @property int|string|null $reviewer_id
 * @property string|null $reviewer_display
 * @property string $status
 * @property string $data
 * @property string|null $original_data
 * @property string|null $note
 * @property \Cake\I18n\DateTime|null $original_modified
 * @property string|null $reason
 * @property \Cake\I18n\DateTime|null $reviewed
 * @property \Cake\I18n\DateTime|null $created
 * @property \Cake\I18n\DateTime|null $modified
 */
class BouncerRecord extends Entity
{
    /**
     * Fields that can be mass assigned using newEntity() or patchEntity().
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'source' => true,
        'primary_key' => true,
        'user_id' => true,
        'user_display' => true,
        'reviewer_id' => true,
        'reviewer_display' => true,
        'status' => true,
        'data' => true,
        'original_data' => true,
        'note' => true,
        'original_modified' => true,
        'reason' => true,
        'reviewed' => true,
        'created' => true,
        'modified' => true,
    ];

    /**
     * Get decoded data as array.
     *
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        if (empty($this->data)) {
            return [];
        }

        return json_decode($this->data, true) ?: [];
    }

    /**
     * Get decoded original data as array.
     *
     * @return array<string, mixed>
     */
    public function getOriginalData(): array
    {
        if (empty($this->original_data)) {
            return [];
        }

        return json_decode($this->original_data, true) ?: [];
    }

    /**
     * Check if this is a new record proposal.
     *
     * @return bool
     */
    public function isNewRecordProposal(): bool
    {
        return $this->primary_key === null;
    }

    /**
     * Check if this is an edit proposal.
     *
     * @return bool
     */
    public function isEditProposal(): bool
    {
        return $this->primary_key !== null;
    }

    /**
     * Check if pending.
     *
     * @return bool
     */
    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * Check if approved.
     *
     * @return bool
     */
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Check if rejected.
     *
     * @return bool
     */
    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    /**
     * Check if this is a delete proposal.
     *
     * @return bool
     */
    public function isDeleteProposal(): bool
    {
        $data = $this->getData();

        return isset($data['_delete']) && $data['_delete'] === true;
    }

    /**
     * @var array<string, mixed>|null
     */
    protected ?array $_mergedData = null;

    /**
     * Check if original_modified field is available (migration was run).
     *
     * @return bool
     */
    public function hasOriginalModified(): bool
    {
        return $this->has('original_modified');
    }

    /**
     * Set merged data for display purposes (not persisted).
     *
     * @param array<string, mixed> $mergedData
     *
     * @return void
     */
    public function setMergedData(array $mergedData): void
    {
        $this->_mergedData = $mergedData;
    }

    /**
     * Check if merged data has been set.
     *
     * @return bool
     */
    public function hasMergedData(): bool
    {
        return $this->_mergedData !== null;
    }

    /**
     * Get merged data if set, otherwise return regular data.
     *
     * @return array<string, mixed>
     */
    public function getMergedData(): array
    {
        return $this->_mergedData ?? $this->getData();
    }

    /**
     * Check if this draft may be stale (source record could have been modified).
     *
     * This only indicates the field is set - actual staleness must be checked
     * by comparing with the current source record.
     *
     * @return bool
     */
    public function canDetectStaleness(): bool
    {
        return $this->hasOriginalModified() && $this->original_modified !== null;
    }

    /**
     * Check if this draft is stale (source record was modified after this draft was created).
     *
     * @param \Cake\Datasource\EntityInterface $currentEntity The current source entity
     *
     * @return bool
     */
    public function isStale(EntityInterface $currentEntity): bool
    {
        if (!$this->canDetectStaleness()) {
            return false;
        }

        $currentModified = $currentEntity->get('modified') ?? $currentEntity->get('created');

        return $currentModified && $currentModified > $this->original_modified;
    }

    /**
     * Build 3-way merge result comparing this draft against the current entity state.
     *
     * Returns null if the draft is not stale (no merge needed).
     * Otherwise returns the merge result with merged data, conflicts, etc.
     *
     * @param \Cake\Datasource\EntityInterface $currentEntity The current source entity
     * @param array<string> $skipFields Fields to skip during merge
     *
     * @return array{merged: array<string, mixed>, conflicts: array<string, array>, autoMerged: array<string, array>, hasConflicts: bool}|null
     */
    public function buildMergeResult(EntityInterface $currentEntity, array $skipFields = ['id', 'created', 'modified', '_delete']): ?array
    {
        if (!$this->isStale($currentEntity)) {
            return null;
        }

        $merger = new ThreeWayMerge();

        return $merger->mergeArrays(
            $this->getOriginalData(),
            $currentEntity->toArray(),
            $this->getData(),
            $skipFields,
        );
    }
}
