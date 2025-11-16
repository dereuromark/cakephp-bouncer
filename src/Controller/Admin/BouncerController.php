<?php

declare(strict_types=1);

namespace Bouncer\Controller\Admin;

use App\Controller\AppController;
use Cake\Event\EventInterface;
use Cake\Http\Exception\NotFoundException;

/**
 * Bouncer Controller
 *
 * @property \Bouncer\Model\Table\BouncerRecordsTable $BouncerRecords
 */
class BouncerController extends AppController
{
    /**
     * Before filter callback.
     *
     * @param \Cake\Event\EventInterface $event Event
     * @return \Cake\Http\Response|null
     */
    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);

        $this->loadModel('Bouncer.BouncerRecords');
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
    }

    /**
     * View method - Review a specific bouncer record with diff
     *
     * @param int|null $id Bouncer Record id.
     * @return \Cake\Http\Response|null
     */
    public function view(?int $id = null)
    {
        $bouncerRecord = $this->BouncerRecords->get($id);

        // Get the current published version for comparison (if edit)
        $currentRecord = null;
        if ($bouncerRecord->isEditProposal()) {
            try {
                $sourceTable = $this->fetchTable($bouncerRecord->source);
                $currentRecord = $sourceTable->get($bouncerRecord->primary_key);
            } catch (\Exception $e) {
                $this->Flash->warning('The original record no longer exists.');
            }
        }

        $this->set(compact('bouncerRecord', 'currentRecord'));
    }

    /**
     * Approve method
     *
     * @param int|null $id Bouncer Record id.
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

                $entity = $sourceTable->getBehavior('Bouncer')->applyApprovedChanges($bouncerRecord);

                if (!$entity) {
                    throw new \RuntimeException('Failed to apply changes to ' . $bouncerRecord->source);
                }

                // Update bouncer record
                $this->BouncerRecords->patchEntity($bouncerRecord, [
                    'status' => 'approved',
                    'reviewer_id' => $this->request->getAttribute('identity')?->getIdentifier(),
                    'reviewed' => new \DateTime(),
                    'reason' => $this->request->getData('reason'),
                    'primary_key' => $entity->get($sourceTable->getPrimaryKey()), // Set for new records
                ]);

                if (!$this->BouncerRecords->save($bouncerRecord)) {
                    throw new \RuntimeException('Failed to update bouncer record.');
                }
            });

            $this->Flash->success('Changes have been approved and published.');
        } catch (\Exception $e) {
            $this->Flash->error('Failed to approve changes: ' . $e->getMessage());

            return $this->redirect(['action' => 'view', $id]);
        }

        return $this->redirect(['action' => 'index']);
    }

    /**
     * Reject method
     *
     * @param int|null $id Bouncer Record id.
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
            'reviewed' => new \DateTime(),
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
