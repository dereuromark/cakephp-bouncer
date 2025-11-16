<?php

declare(strict_types=1);

namespace Bouncer\Model\Entity;

use Cake\ORM\Entity;

/**
 * BouncerRecord Entity
 *
 * @property int $id
 * @property string $source
 * @property int|null $primary_key
 * @property int $user_id
 * @property int|null $reviewer_id
 * @property string $status
 * @property string $data
 * @property string|null $original_data
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
        'reviewer_id' => true,
        'status' => true,
        'data' => true,
        'original_data' => true,
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
}
