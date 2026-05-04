<?php
/**
 * @var \App\View\AppView $this
 * @var \Bouncer\Model\Entity\BouncerRecord $bouncerRecord
 * @var \Cake\Datasource\EntityInterface|null $currentRecord
 * @var array<string, mixed>|null $conflict
 */

$diffs = $this->Bouncer->calculateDiffs($bouncerRecord, $currentRecord);
?>
<?= $this->Bouncer->diffStyles() ?>
<div class="bouncer view content">
    <h1><?= __d('bouncer', 'Review Proposed Changes') ?></h1>

    <?php if (!empty($conflict) && $conflict['hasConflicts']) { ?>
        <div class="alert alert-warning">
            <strong><?= __d('bouncer', 'Conflict Detected!') ?></strong>
            <?= __d('bouncer', 'This record was modified after the draft was created, and some of the same fields were changed. Please review and merge the changes before approval.') ?>
            <div class="mt-2">
                <?= $this->Html->link(
                    __d('bouncer', 'Resolve Conflicts'),
                    ['action' => 'resolve', $bouncerRecord->id],
                    ['class' => 'btn btn-warning btn-sm']
                ) ?>
            </div>
        </div>
    <?php } elseif (!empty($conflict)) { ?>
        <div class="alert alert-info">
            <strong><?= __d('bouncer', 'Note:') ?></strong>
            <?= __d('bouncer', 'This record was modified after the draft was created, but the changes affect different fields. You can proceed with approval.') ?>
            <div class="mt-2">
                <?= $this->Html->link(
                    __d('bouncer', 'View 3-Way Comparison'),
                    ['action' => 'resolve', $bouncerRecord->id],
                    ['class' => 'btn btn-info btn-sm']
                ) ?>
            </div>
        </div>
    <?php } ?>

    <div class="card mb-3">
        <div class="card-header">
            <strong><?= __d('bouncer', 'Bouncer Record Details') ?></strong>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <table class="table table-sm">
                        <tr>
                            <th><?= __d('bouncer', 'ID') ?></th>
                            <td><?= $this->Number->format($bouncerRecord->id) ?></td>
                        </tr>
                        <tr>
                            <th><?= __d('bouncer', 'Source') ?></th>
                            <td><code><?= h($bouncerRecord->source) ?></code></td>
                        </tr>
                        <tr>
                            <th><?= __d('bouncer', 'Record Type') ?></th>
                            <td><?= $this->Bouncer->recordTypeBadge($bouncerRecord) ?></td>
                        </tr>
                        <tr>
                            <th><?= __d('bouncer', 'Status') ?></th>
                            <td><?= $this->Bouncer->statusBadge($bouncerRecord->status) ?></td>
                        </tr>
                    </table>
                </div>
                <div class="col-md-6">
                    <table class="table table-sm">
                        <tr>
                            <th><?= __d('bouncer', 'Submitted By') ?></th>
                            <td><?= $this->Bouncer->formatUser($bouncerRecord->user_id, $bouncerRecord->user_display) ?></td>
                        </tr>
                        <tr>
                            <th><?= __d('bouncer', 'Submitted') ?></th>
                            <td><?= h($bouncerRecord->created) ?></td>
                        </tr>
                        <tr>
                            <th><?= __d('bouncer', 'Modified') ?></th>
                            <td><?= h($bouncerRecord->modified) ?></td>
                        </tr>
                        <?php if ($bouncerRecord->reviewer_id) { ?>
                            <tr>
                                <th><?= __d('bouncer', 'Reviewed By') ?></th>
                                <td><?= $this->Bouncer->formatUser($bouncerRecord->reviewer_id, $bouncerRecord->reviewer_display) ?></td>
                            </tr>
                            <tr>
                                <th><?= __d('bouncer', 'Reviewed') ?></th>
                                <td><?= h($bouncerRecord->reviewed) ?></td>
                            </tr>
                        <?php } ?>
                    </table>
                </div>
            </div>

            <?php if ($bouncerRecord->note) { ?>
                <div class="alert alert-secondary mt-3">
                    <strong><?= __d('bouncer', 'User Note:') ?></strong> <?= h($bouncerRecord->note) ?>
                </div>
            <?php } ?>

            <?php if ($bouncerRecord->reason) { ?>
                <div class="alert alert-info mt-3">
                    <strong><?= __d('bouncer', 'Reviewer Reason:') ?></strong> <?= h($bouncerRecord->reason) ?>
                </div>
            <?php } ?>
        </div>
    </div>

    <div class="card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <strong><?= __d('bouncer', 'Proposed Changes') ?></strong>
            <?php if ($bouncerRecord->isEditProposal() && $currentRecord && $diffs) { ?>
                <?= $this->Bouncer->diffToggleButtons() ?>
            <?php } ?>
        </div>
        <div class="card-body">
            <?php if ($bouncerRecord->isEditProposal() && $currentRecord) { ?>
                <div id="inline-diff-view">
                    <?= $this->Bouncer->diffInline($diffs) ?>
                </div>
                <div id="side-diff-view" hidden>
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
                <strong><?= __d('bouncer', 'Actions') ?></strong>
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
                        <?= $this->Form->button(__d('bouncer', 'Approve Changes'), ['class' => 'btn btn-success']) ?>
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
                        <?= $this->Form->button(__d('bouncer', 'Reject Changes'), ['class' => 'btn btn-danger']) ?>
                        <?= $this->Form->end() ?>
                    </div>
                </div>
            </div>
        </div>
    <?php } elseif ($bouncerRecord->status === 'rejected') { ?>
        <div class="card">
            <div class="card-header">
                <strong><?= __d('bouncer', 'Actions') ?></strong>
            </div>
            <div class="card-body">
                <p class="text-muted"><?= __d('bouncer', 'This proposal was rejected.') ?></p>
                <?= $this->Form->postButton(
                    __d('bouncer', 'Reopen for Review'),
                    ['action' => 'reopen', $bouncerRecord->id],
                    [
                        'class' => 'btn btn-warning',
                        'form' => [
                            'class' => 'd-inline',
                            'data-confirm-message' => __d('bouncer', 'Are you sure you want to reopen this proposal?'),
                        ],
                    ]
                ) ?>
            </div>
        </div>
    <?php } ?>

    <div class="mt-3">
        <?= $this->Html->link(__d('bouncer', 'Back to List'), ['action' => 'index'], ['class' => 'btn btn-secondary']) ?>
    </div>
</div>

<?= $this->Bouncer->diffToggleScript() ?>
