<?php
/**
 * @var \App\View\AppView $this
 * @var \Bouncer\Model\Entity\BouncerRecord $bouncerRecord
 * @var \Cake\Datasource\EntityInterface|null $currentRecord
 * @var array|null $conflict
 */

$diffs = $this->Bouncer->calculateDiffs($bouncerRecord, $currentRecord);
?>
<?= $this->Bouncer->diffStyles() ?>
<div class="bouncer view content">
    <h1><?= __('Review Proposed Changes') ?></h1>

    <?php if (!empty($conflict) && $conflict['hasConflicts']) { ?>
        <div class="alert alert-warning">
            <strong><?= __('Conflict Detected!') ?></strong>
            <?= __('This record was modified after the draft was created, and some of the same fields were changed. Please review and merge the changes before approval.') ?>
            <div class="mt-2">
                <?= $this->Html->link(
                    __('Resolve Conflicts'),
                    ['action' => 'resolve', $bouncerRecord->id],
                    ['class' => 'btn btn-warning btn-sm']
                ) ?>
            </div>
        </div>
    <?php } elseif (!empty($conflict)) { ?>
        <div class="alert alert-info">
            <strong><?= __('Note:') ?></strong>
            <?= __('This record was modified after the draft was created, but the changes affect different fields. You can proceed with approval.') ?>
            <div class="mt-2">
                <?= $this->Html->link(
                    __('View 3-Way Comparison'),
                    ['action' => 'resolve', $bouncerRecord->id],
                    ['class' => 'btn btn-info btn-sm']
                ) ?>
            </div>
        </div>
    <?php } ?>

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
                            <td><?= $this->Bouncer->recordTypeBadge($bouncerRecord) ?></td>
                        </tr>
                        <tr>
                            <th><?= __('Status') ?></th>
                            <td><?= $this->Bouncer->statusBadge($bouncerRecord->status) ?></td>
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

            <?php if ($bouncerRecord->note) { ?>
                <div class="alert alert-secondary mt-3">
                    <strong><?= __('User Note:') ?></strong> <?= h($bouncerRecord->note) ?>
                </div>
            <?php } ?>

            <?php if ($bouncerRecord->reason) { ?>
                <div class="alert alert-info mt-3">
                    <strong><?= __('Reviewer Reason:') ?></strong> <?= h($bouncerRecord->reason) ?>
                </div>
            <?php } ?>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong><?= __('Proposed Changes') ?></strong>
            <?php if ($bouncerRecord->isEditProposal() && $currentRecord && $diffs) { ?>
                <?= $this->Bouncer->diffToggleButtons() ?>
            <?php } ?>
        </div>
        <div class="card-body">
            <?php if ($bouncerRecord->isEditProposal() && $currentRecord) { ?>
                <div id="inline-diff-view">
                    <?= $this->Bouncer->diffInline($diffs) ?>
                </div>
                <div id="side-diff-view" style="display: none;">
                    <?= $this->Bouncer->diffSideBySide($diffs) ?>
                </div>
            <?php } else { ?>
                <?= $this->Bouncer->newRecordTable($bouncerRecord) ?>
            <?php } ?>

            <?= $this->Bouncer->rawJsonDetails($bouncerRecord) ?>
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

<?= $this->Bouncer->diffToggleScript() ?>
