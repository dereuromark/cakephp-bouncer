<?php

declare(strict_types=1);

namespace Bouncer\Controller\Admin;

use App\Controller\AppController;
use Bouncer\Lib\ThreeWayMerge;
use Cake\Core\Configure;
use Cake\Event\EventInterface;
use Cake\Http\Exception\ForbiddenException;
use Cake\Log\Log;
use Closure;
use DateTime;
use Exception;
use RuntimeException;
use Throwable;

/**
 * Bouncer Controller
 *
 * @property \Bouncer\Model\Table\BouncerRecordsTable $BouncerRecords
 */
class BouncerController extends AppController
{
    use LoadHelperTrait;

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

        $this->enforceAccessCheck();
        $this->loadHelpers();

        // Configure layout
        $adminLayout = Configure::read('Bouncer.adminLayout');
        if ($adminLayout === false) {
            // Disable plugin layout, use app's default
        } elseif ($adminLayout === null) {
            // Use plugin's isolated Bootstrap 5 layout
            $this->viewBuilder()->setLayout('Bouncer.bouncer');
        } else {
            // Use custom layout
            $this->viewBuilder()->setLayout($adminLayout);
        }
    }

    /**
     * Optional defense-in-depth access gate.
     *
     * Bouncer manages content moderation / conflict resolution rules — useful
     * to tighten beyond the host AppController's auth (e.g. "moderators only,
     * not all admins"). Set `Bouncer.accessCheck` to a Closure that receives
     * the current request and returns literal `true` to grant access; anything
     * else (returns false, returns a truthy non-bool, throws) yields a 403.
     *
     * Unset = no-op (host AppController auth alone applies).
     *
     * @throws \Cake\Http\Exception\ForbiddenException When the configured Closure rejects the request.
     *
     * @return void
     */
    protected function enforceAccessCheck(): void
    {
        $check = Configure::read('Bouncer.accessCheck');
        if ($check === null) {
            return;
        }
        if (!($check instanceof Closure)) {
            throw new ForbiddenException('Bouncer.accessCheck must be a Closure');
        }

        // Coexist with cakephp/authorization: the gate IS the authorization
        // decision, so silence the policy check.
        if ($this->components()->has('Authorization') && method_exists($this->components()->get('Authorization'), 'skipAuthorization')) {
            $this->components()->get('Authorization')->skipAuthorization();
        }

        try {
            $allowed = $check($this->request) === true;
        } catch (ForbiddenException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::warning(sprintf('Bouncer.accessCheck threw %s: %s', $e::class, $e->getMessage()));

            throw new ForbiddenException('Bouncer admin access denied');
        }

        if (!$allowed) {
            throw new ForbiddenException('Bouncer admin access denied');
        }
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
                        // Stale! Build 3-way diff and auto-merge
                        $conflict = $this->buildThreeWayDiff(
                            $bouncerRecord->getOriginalData(),
                            $currentRecord->toArray(),
                            $bouncerRecord->getData(),
                        );

                        // Auto-apply merged data to bouncer record for display
                        // This updates in-memory only, not saved to database yet
                        if (!empty($conflict['merged'])) {
                            $bouncerRecord->setMergedData($conflict['merged']);
                        }
                    }
                }
            } catch (Exception $e) {
                $this->Flash->warning('The original record no longer exists.');
            }
        }

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

        // Check if record is stale (modified after draft was created)
        $isStale = false;
        if ($bouncerRecord->canDetectStaleness()) {
            $currentModified = $currentRecord->get('modified') ?? $currentRecord->get('created');
            $isStale = $currentModified && $currentModified > $bouncerRecord->original_modified;
        }

        if (!$isStale) {
            $this->Flash->info('No changes detected since draft creation. You can proceed with normal approval.');

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
                $bouncerRecord->data = json_encode($mergedData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                // Update original_modified to current time to mark conflict as resolved
                $bouncerRecord->original_modified = $currentRecord->get('modified') ?? $currentRecord->get('created');
                // Update original_data to current state
                $bouncerRecord->original_data = json_encode($currentRecord->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

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

            // Skip fields not in the original proposal - we only care about proposed changes
            $inProposed = array_key_exists($field, $proposed);

            // Skip if no changes
            $currentChanged = $origValue !== $currValue;
            $proposedChanged = $origValue !== $propValue;

            if (!$currentChanged && !$proposedChanged) {
                continue;
            }

            // If only one side changed, use that change
            if (!$currentChanged) {
                // Only proposed changed - keep proposed value (already in $merged)
                continue;
            }
            if (!$proposedChanged) {
                // Only current changed - update merged only if field was in proposed
                if ($inProposed) {
                    $merged[$field] = $currValue;
                }

                continue;
            }

            // Both changed - only process if field was in proposed
            if (!$inProposed) {
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

        // Auto-merge stale records before approval
        if ($bouncerRecord->isEditProposal() && $bouncerRecord->canDetectStaleness()) {
            try {
                $sourceTable = $this->fetchTable($bouncerRecord->source);
                $currentRecord = $sourceTable->get($bouncerRecord->primary_key);
                $currentModified = $currentRecord->get('modified') ?? $currentRecord->get('created');

                if ($currentModified && $currentModified > $bouncerRecord->original_modified) {
                    // Record is stale - auto-merge before applying
                    $conflict = $this->buildThreeWayDiff(
                        $bouncerRecord->getOriginalData(),
                        $currentRecord->toArray(),
                        $bouncerRecord->getData(),
                    );

                    if ($conflict['hasConflicts']) {
                        $this->Flash->error('This record has unresolved conflicts. Please resolve them first.');

                        return $this->redirect(['action' => 'resolve', $id]);
                    }

                    // Set merged data - this prevents the behavior from auto-merging again
                    $bouncerRecord->setMergedData($conflict['merged']);
                }
            } catch (Exception $e) {
                // Source record no longer exists - continue with approval (will fail gracefully)
            }
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

        $reason = trim((string)$this->request->getData('reason'));
        if ($reason === '') {
            $this->Flash->error(__d('bouncer', 'A rejection reason is required.'));

            return $this->redirect(['action' => 'view', $id]);
        }

        $this->BouncerRecords->patchEntity($bouncerRecord, [
            'status' => 'rejected',
            'reviewer_id' => $this->request->getAttribute('identity')?->getIdentifier(),
            'reviewed' => new DateTime(),
            'reason' => $reason,
        ]);

        if ($this->BouncerRecords->save($bouncerRecord)) {
            $this->Flash->success('Changes have been rejected.');
        } else {
            $this->Flash->error('Failed to reject changes.');
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Reopen a rejected record - put it back to pending status.
     *
     * @param int|null $id Bouncer Record id.
     *
     * @return \Cake\Http\Response|null
     */
    public function reopen(?int $id = null)
    {
        $this->request->allowMethod(['post', 'put']);

        $bouncerRecord = $this->BouncerRecords->get($id);

        if ($bouncerRecord->status !== 'rejected') {
            $this->Flash->error('Only rejected records can be reopened.');

            return $this->redirect(['action' => 'index']);
        }

        $this->BouncerRecords->patchEntity($bouncerRecord, [
            'status' => 'pending',
            'reviewer_id' => null,
            'reviewed' => null,
            'reason' => null,
        ]);

        if ($this->BouncerRecords->save($bouncerRecord)) {
            $this->Flash->success('Record has been reopened for review.');
        } else {
            $this->Flash->error('Failed to reopen record.');
        }

        return $this->redirect(['action' => 'view', $id]);
    }

    /**
     * Delete method
     *
     * Only pending bouncer records may be deleted via this action. Approved and rejected
     * records form the moderation audit trail and must not be silently erased; preserving
     * them is core to the plugin's value proposition (a moderation workflow with traceability).
     * Attempts to delete non-pending records are rejected with a flash error.
     *
     * @param int|null $id Bouncer Record id.
     *
     * @return \Cake\Http\Response|null
     */
    public function delete(?int $id = null)
    {
        $this->request->allowMethod(['post', 'delete']);

        $bouncerRecord = $this->BouncerRecords->get($id);

        if ($bouncerRecord->get('status') !== 'pending') {
            $this->Flash->error(
                'Only pending bouncer records can be deleted; resolved records are kept as audit history.',
            );

            return $this->redirect(['action' => 'view', $id]);
        }

        if ($this->BouncerRecords->delete($bouncerRecord)) {
            $this->Flash->success('Bouncer record has been deleted.');
        } else {
            $this->Flash->error('Failed to delete bouncer record.');
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Truncate bouncer_records. Debug-only convenience for development /
     * demo environments — refuses to run when `Configure::read('debug')`
     * is false so a misconfigured route can't wipe production data.
     *
     * @throws \Cake\Http\Exception\ForbiddenException When debug is off.
     *
     * @return \Cake\Http\Response|null
     */
    public function reset()
    {
        $this->request->allowMethod(['post']);
        if (!Configure::read('debug')) {
            throw new ForbiddenException('Bouncer reset is only available in debug mode.');
        }

        $connection = $this->BouncerRecords->getConnection();
        $connection->execute('TRUNCATE TABLE ' . $connection->getDriver()->quoteIdentifier($this->BouncerRecords->getTable()));

        $this->Flash->success(__d('bouncer', 'All bouncer records have been deleted.'));

        return $this->redirect(['action' => 'index']);
    }
}
