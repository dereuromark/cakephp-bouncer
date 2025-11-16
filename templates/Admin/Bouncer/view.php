<?php
/**
 * @var \App\View\AppView $this
 * @var \Bouncer\Model\Entity\BouncerRecord $bouncerRecord
 * @var \Cake\Datasource\EntityInterface|null $currentRecord
 */
?>
<div class="bouncer view content">
    <h1><?= __('Review Proposed Changes') ?></h1>

    <div class="card mb-3">
        <div class="card-header">
            <strong><?= __('Bouncer Record Details') ?></strong>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-sm">
                        <tr>
                            <th><?= __('ID') ?></th>
                            <td><?= $this->Number->format($bouncerRecord->id) ?></td>
                        </tr>
                        <tr>
                            <th><?= __('Table') ?></th>
                            <td><?= h($bouncerRecord->source) ?></td>
                        </tr>
                        <tr>
                            <th><?= __('Record Type') ?></th>
                            <td>
                                <?php if ($bouncerRecord->isNewRecordProposal()) { ?>
                                    <span class="badge bg-success">New Record</span>
                                <?php } else { ?>
                                    <span class="badge bg-info">Edit to Record #<?= $bouncerRecord->primary_key ?></span>
                                <?php } ?>
                            </td>
                        </tr>
                        <tr>
                            <th><?= __('Status') ?></th>
                            <td><span class="badge bg-warning"><?= h($bouncerRecord->status) ?></span></td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm">
                        <tr>
                            <th><?= __('Submitted By') ?></th>
                            <td>User #<?= $this->Number->format($bouncerRecord->user_id) ?></td>
                        </tr>
                        <tr>
                            <th><?= __('Submitted') ?></th>
                            <td><?= h($bouncerRecord->created) ?></td>
                        </tr>
                        <tr>
                            <th><?= __('Modified') ?></th>
                            <td><?= h($bouncerRecord->modified) ?></td>
                        </tr>
                        <?php if ($bouncerRecord->reviewer_id) { ?>
                            <tr>
                                <th><?= __('Reviewed By') ?></th>
                                <td>User #<?= $this->Number->format($bouncerRecord->reviewer_id) ?></td>
                            </tr>
                            <tr>
                                <th><?= __('Reviewed') ?></th>
                                <td><?= h($bouncerRecord->reviewed) ?></td>
                            </tr>
                        <?php } ?>
                    </table>
                </div>
            </div>

            <?php if ($bouncerRecord->reason) { ?>
                <div class="alert alert-info mt-3">
                    <strong><?= __('Reason:') ?></strong> <?= h($bouncerRecord->reason) ?>
                </div>
            <?php } ?>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <strong><?= __('Proposed Changes') ?></strong>
        </div>
        <div class="card-body">
            <?php if ($bouncerRecord->isEditProposal() && $currentRecord) { ?>
                <h5><?= __('Changes (Current → Proposed)') ?></h5>
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Field</th>
                            <th>Current Value</th>
                            <th>Proposed Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $proposedData = $bouncerRecord->getData();
                        $currentData = $currentRecord->toArray();
                        $allFields = array_unique(array_merge(array_keys($currentData), array_keys($proposedData)));
                        sort($allFields);

                        foreach ($allFields as $field) {
                            if (in_array($field, ['created', 'modified'])) {
                                continue;
                            }

                            $currentValue = $currentData[$field] ?? null;
                            $proposedValue = $proposedData[$field] ?? null;

                            if ($currentValue == $proposedValue) {
                                continue; // Skip unchanged fields
                            }
                            ?>
                            <tr>
                                <td><strong><?= h($field) ?></strong></td>
                                <td><?= h($currentValue) ?></td>
                                <td class="table-warning"><strong><?= h($proposedValue) ?></strong></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            <?php } else { ?>
                <h5><?= __('New Record Data') ?></h5>
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>Field</th>
                            <th>Value</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bouncerRecord->getData() as $field => $value) { ?>
                            <?php if (in_array($field, ['created', 'modified'])) {
                                continue;
                            } ?>
                            <tr>
                                <td><strong><?= h($field) ?></strong></td>
                                <td><?= h($value) ?></td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            <?php } ?>

            <details class="mt-3">
                <summary><strong><?= __('Raw JSON Data') ?></strong></summary>
                <pre class="bg-light p-3 mt-2"><code><?= h(json_encode($bouncerRecord->getData(), JSON_PRETTY_PRINT)) ?></code></pre>
            </details>
        </div>
    </div>

    <?php if ($bouncerRecord->isPending()) { ?>
        <div class="card">
            <div class="card-header">
                <strong><?= __('Actions') ?></strong>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <?= $this->Form->create(null, ['url' => ['action' => 'approve', $bouncerRecord->id]]) ?>
                        <?= $this->Form->control('reason', [
                            'label' => 'Approval Note (optional)',
                            'type' => 'textarea',
                            'rows' => 2,
                        ]) ?>
                        <?= $this->Form->button(__('Approve Changes'), ['class' => 'btn btn-success']) ?>
                        <?= $this->Form->end() ?>
                    </div>
                    <div class="col-md-6">
                        <?= $this->Form->create(null, ['url' => ['action' => 'reject', $bouncerRecord->id]]) ?>
                        <?= $this->Form->control('reason', [
                            'label' => 'Rejection Reason',
                            'type' => 'textarea',
                            'rows' => 2,
                            'required' => true,
                        ]) ?>
                        <?= $this->Form->button(__('Reject Changes'), ['class' => 'btn btn-danger']) ?>
                        <?= $this->Form->end() ?>
                    </div>
                </div>
            </div>
        </div>
    <?php } ?>

    <div class="mt-3">
        <?= $this->Html->link(__('Back to List'), ['action' => 'index'], ['class' => 'btn btn-secondary']) ?>
    </div>
</div>
