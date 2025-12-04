<?php

declare(strict_types=1);

namespace Bouncer\Controller\Admin;

use App\Controller\AppController;
use Bouncer\Lib\ThreeWayMerge;
use Cake\Event\EventInterface;
use DateTime;
use Exception;
use RuntimeException;

/**
 * Bouncer Controller
 *
 * @property \Bouncer\Model\Table\BouncerRecordsTable $BouncerRecords
 */
class BouncerController extends AppController
{
    /**
     * @var string|null
     */
    protected ?string $defaultTable = 'Bouncer.BouncerRecords';

    /**
     * Before filter callback.
     *
     * @param \Cake\Event\EventInterface $event Event
     *
     * @return void
     */
    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
    }

    /**
     * Index method - List all pending bouncer records
     *
     * @return \Cake\Http\Response|null
     */
    public function index()
    {
        $query = $this->BouncerRecords->find();

        // Filter by status
        $status = $this->request->getQuery('status', 'pending');
        if ($status && $status !== 'all') {
            $query->where(['status' => $status]);
        }

        // Filter by source table
        $source = $this->request->getQuery('source');
        if ($source) {
            $query->where(['source' => $source]);
        }

        // Filter by user
        $userId = $this->request->getQuery('user_id');
        if ($userId) {
            $query->where(['user_id' => $userId]);
        }

        $bouncerRecords = $this->paginate($query->orderBy(['created' => 'DESC']));

        // Get distinct sources for filter
        $sources = $this->BouncerRecords->find()
            ->select(['source'])
            ->distinct(['source'])
            ->orderBy(['source' => 'ASC'])
            ->all()
            ->extract('source')
            ->toArray();

        $this->set(compact('bouncerRecords', 'sources', 'status', 'source', 'userId'));

        return null;
    }

    /**
     * View method - Review a specific bouncer record with diff
     *
     * @param int|null $id Bouncer Record id.
     *
     * @return \Cake\Http\Response|null
     */
    public function view(?int $id = null)
    {
        $bouncerRecord = $this->BouncerRecords->get($id);

        // Get the current published version for comparison (if edit)
        $currentRecord = null;
        $conflict = null;

        if ($bouncerRecord->isEditProposal()) {
            try {
                $sourceTable = $this->fetchTable($bouncerRecord->source);
                $currentRecord = $sourceTable->get($bouncerRecord->primary_key);

                // Check for staleness on-demand (only for pending records)
                if ($bouncerRecord->isPending() && $bouncerRecord->canDetectStaleness()) {
                    $currentModified = $currentRecord->get('modified') ?? $currentRecord->get('created');
                    if ($currentModified && $currentModified > $bouncerRecord->original_modified) {
                        // Stale! Build 3-way diff
                        $conflict = $this->buildThreeWayDiff(
                            $bouncerRecord->getOriginalData(),
                            $currentRecord->toArray(),
                            $bouncerRecord->getData(),
                        );
                    }
                }
            } catch (Exception $e) {
                $this->Flash->warning('The original record no longer exists.');
            }
        }

        $this->viewBuilder()->addHelper('Bouncer.Bouncer');
        $this->set(compact('bouncerRecord', 'currentRecord', 'conflict'));

        return null;
    }

    /**
     * Resolve method - 3-way merge interface for conflicts
     *
     * @param int|null $id Bouncer Record id.
     *
     * @return \Cake\Http\Response|null
     */
    public function resolve(?int $id = null)
    {
        $bouncerRecord = $this->BouncerRecords->get($id);

        if (!$bouncerRecord->isPending()) {
            $this->Flash->error('This record has already been processed.');

            return $this->redirect(['action' => 'index']);
        }

        if (!$bouncerRecord->isEditProposal()) {
            $this->Flash->warning('Conflict resolution is only available for edit proposals.');

            return $this->redirect(['action' => 'view', $id]);
        }

        // Load current record and check for conflict
        try {
            $sourceTable = $this->fetchTable($bouncerRecord->source);
            $currentRecord = $sourceTable->get($bouncerRecord->primary_key);
        } catch (Exception $e) {
            $this->Flash->error('The original record no longer exists.');

            return $this->redirect(['action' => 'index']);
        }

        // Check if there's actually a conflict
        $hasConflict = false;
        if ($bouncerRecord->canDetectStaleness()) {
            $currentModified = $currentRecord->get('modified') ?? $currentRecord->get('created');
            $hasConflict = $currentModified && $currentModified > $bouncerRecord->original_modified;
        }

        if (!$hasConflict) {
            $this->Flash->info('No conflict detected. You can proceed with normal approval.');

            return $this->redirect(['action' => 'view', $id]);
        }

        $conflict = $this->buildThreeWayDiff(
            $bouncerRecord->getOriginalData(),
            $currentRecord->toArray(),
            $bouncerRecord->getData(),
        );

        if ($this->request->is(['post', 'put'])) {
            $mergedData = $this->request->getData('merged');

            if ($mergedData) {
                // Update bouncer record with merged data
                $bouncerRecord->data = json_encode($mergedData, JSON_THROW_ON_ERROR);
                // Update original_modified to current time to mark conflict as resolved
                $bouncerRecord->original_modified = $currentRecord->get('modified') ?? $currentRecord->get('created');
                // Update original_data to current state
                $bouncerRecord->original_data = json_encode($currentRecord->toArray(), JSON_THROW_ON_ERROR);

                if ($this->BouncerRecords->save($bouncerRecord)) {
                    $this->Flash->success('Conflict resolved. Ready for final approval.');

                    return $this->redirect(['action' => 'view', $id]);
                }

                $this->Flash->error('Failed to save merged changes.');
            }
        }

        $this->set(compact('bouncerRecord', 'currentRecord', 'conflict'));

        return null;
    }

    /**
     * Build a 3-way diff for conflict resolution.
     *
     * Attempts to auto-merge non-overlapping changes and identifies true conflicts.
     *
     * @param array<string, mixed> $original Original data when draft was created
     * @param array<string, mixed> $current Current live data
     * @param array<string, mixed> $proposed Proposed changes in draft
     *
     * @return array{original: array, current: array, proposed: array, merged: array, conflicts: array<string, array>, autoMerged: array<string, array>, hasConflicts: bool}
     */
    protected function buildThreeWayDiff(array $original, array $current, array $proposed): array
    {
        $conflicts = [];
        $autoMerged = [];
        $merged = $proposed; // Start with proposed as base

        $allFields = array_unique(array_merge(
            array_keys($original),
            array_keys($current),
            array_keys($proposed),
        ));

        // Filter out internal fields
        $allFields = array_filter($allFields, function ($field) {
            return !in_array($field, ['created', 'modified', 'id', '_delete'], true);
        });

        $merger = new ThreeWayMerge();

        foreach ($allFields as $field) {
            $origValue = $original[$field] ?? null;
            $currValue = $current[$field] ?? null;
            $propValue = $proposed[$field] ?? null;

            // Skip if no changes
            $currentChanged = $origValue !== $currValue;
            $proposedChanged = $origValue !== $propValue;

            if (!$currentChanged && !$proposedChanged) {
                continue;
            }

            // If only one side changed, use that change
            if (!$currentChanged) {
                $merged[$field] = $propValue;

                continue;
            }
            if (!$proposedChanged) {
                $merged[$field] = $currValue;

                continue;
            }

            // Both changed - try to merge if strings
            if ($currValue === $propValue) {
                // Same change on both sides
                $merged[$field] = $currValue;

                continue;
            }

            // Try smart merge for strings
            if (is_string($origValue) && is_string($currValue) && is_string($propValue)) {
                $mergeResult = $merger->mergeStrings((string)$origValue, (string)$currValue, (string)$propValue);

                if ($mergeResult['status'] === ThreeWayMerge::MERGED) {
                    $merged[$field] = $mergeResult['result'];
                    $autoMerged[$field] = [
                        'original' => $origValue,
                        'current' => $currValue,
                        'proposed' => $propValue,
                        'result' => $mergeResult['result'],
                        'currentChanges' => $mergeResult['currentChanges'],
                        'proposedChanges' => $mergeResult['proposedChanges'],
                    ];

                    continue;
                }
            }

            // True conflict - cannot auto-merge
            $conflicts[$field] = [
                'original' => $origValue,
                'current' => $currValue,
                'proposed' => $propValue,
            ];
        }

        return [
            'original' => $original,
            'current' => $current,
            'proposed' => $proposed,
            'merged' => $merged,
            'conflicts' => $conflicts,
            'autoMerged' => $autoMerged,
            'hasConflicts' => (bool)$conflicts,
        ];
    }

    /**
     * Approve method
     *
     * @param int|null $id Bouncer Record id.
     *
     * @return \Cake\Http\Response|null
     */
    public function approve(?int $id = null)
    {
        $this->request->allowMethod(['post', 'put']);

        $bouncerRecord = $this->BouncerRecords->get($id);

        if (!$bouncerRecord->isPending()) {
            $this->Flash->error('This record has already been processed.');

            return $this->redirect(['action' => 'index']);
        }

        $connection = $this->BouncerRecords->getConnection();

        try {
            $connection->transactional(function () use ($bouncerRecord) {
                // Apply the changes to the actual table
                $sourceTable = $this->fetchTable($bouncerRecord->source);

                // Add behavior if not already present
                if (!$sourceTable->hasBehavior('Bouncer')) {
                    $sourceTable->addBehavior('Bouncer.Bouncer');
                }

                /** @var \Bouncer\Model\Behavior\BouncerBehavior $behavior */
                /** @phpstan-ignore-next-line */
                $behavior = $sourceTable->getBehavior('Bouncer');
                $entity = $behavior->applyApprovedChanges($bouncerRecord);

                if (!$entity) {
                    throw new RuntimeException('Failed to apply changes to ' . $bouncerRecord->source);
                }

                $primaryKeyField = $sourceTable->getPrimaryKey();
                $primaryKeyValue = is_object($entity) ? $entity->get(is_array($primaryKeyField) ? $primaryKeyField[0] : $primaryKeyField) : null;

                // Update bouncer record
                $this->BouncerRecords->patchEntity($bouncerRecord, [
                    'status' => 'approved',
                    'reviewer_id' => $this->request->getAttribute('identity')?->getIdentifier(),
                    'reviewed' => new DateTime(),
                    'reason' => $this->request->getData('reason'),
                    'primary_key' => $primaryKeyValue, // Set for new records
                ]);

                if (!$this->BouncerRecords->save($bouncerRecord)) {
                    throw new RuntimeException('Failed to update bouncer record.');
                }
            });

            $this->Flash->success('Changes have been approved and published.');
        } catch (Exception $e) {
            $this->Flash->error('Failed to approve changes: ' . $e->getMessage());

            return $this->redirect(['action' => 'view', $id]);
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Reject method
     *
     * @param int|null $id Bouncer Record id.
     *
     * @return \Cake\Http\Response|null
     */
    public function reject(?int $id = null)
    {
        $this->request->allowMethod(['post', 'put', 'delete']);

        $bouncerRecord = $this->BouncerRecords->get($id);

        if (!$bouncerRecord->isPending()) {
            $this->Flash->error('This record has already been processed.');

            return $this->redirect(['action' => 'index']);
        }

        $this->BouncerRecords->patchEntity($bouncerRecord, [
            'status' => 'rejected',
            'reviewer_id' => $this->request->getAttribute('identity')?->getIdentifier(),
            'reviewed' => new DateTime(),
            'reason' => $this->request->getData('reason'),
        ]);

        if ($this->BouncerRecords->save($bouncerRecord)) {
            $this->Flash->success('Changes have been rejected.');
        } else {
            $this->Flash->error('Failed to reject changes.');
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Delete method
     *
     * @param int|null $id Bouncer Record id.
     *
     * @return \Cake\Http\Response|null
     */
    public function delete(?int $id = null)
    {
        $this->request->allowMethod(['post', 'delete']);

        $bouncerRecord = $this->BouncerRecords->get($id);

        if ($this->BouncerRecords->delete($bouncerRecord)) {
            $this->Flash->success('Bouncer record has been deleted.');
        } else {
            $this->Flash->error('Failed to delete bouncer record.');
        }

        return $this->redirect(['action' => 'index']);
    }
}
