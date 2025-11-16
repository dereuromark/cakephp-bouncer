<?php
/**
 * @var \App\View\AppView $this
 * @var iterable<\Bouncer\Model\Entity\BouncerRecord> $bouncerRecords
 * @var array<string> $sources
 * @var string $status
 * @var string|null $source
 * @var int|null $userId
 */
?>
<div class="bouncer index content">
    <h1><?= __('Pending Approvals') ?></h1>

    <div class="filters card mb-3">
        <div class="card-body">
            <?= $this->Form->create(null, ['type' => 'get', 'valueSources' => 'query']) ?>
            <div class="row">
                <div class="col-md-3">
                    <?= $this->Form->control('status', [
                        'options' => [
                            'all' => 'All',
                            'pending' => 'Pending',
                            'approved' => 'Approved',
                            'rejected' => 'Rejected',
                            'superseded' => 'Superseded',
                        ],
                        'default' => $status,
                        'label' => 'Status',
                    ]) ?>
                </div>
                <div class="col-md-3">
                    <?= $this->Form->control('source', [
                        'options' => array_combine($sources, $sources),
                        'empty' => 'All Tables',
                        'default' => $source,
                        'label' => 'Table',
                    ]) ?>
                </div>
                <div class="col-md-3">
                    <?= $this->Form->control('user_id', [
                        'label' => 'User ID',
                        'default' => $userId,
                    ]) ?>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <?= $this->Form->button(__('Filter'), ['class' => 'btn btn-primary']) ?>
                    <?= $this->Html->link(__('Reset'), ['action' => 'index'], ['class' => 'btn btn-secondary ms-2']) ?>
                </div>
            </div>
            <?= $this->Form->end() ?>
        </div>
    </div>

    <div class="table-responsive">
        <table class="table table-striped">
            <thead>
                <tr>
                    <th><?= $this->Paginator->sort('id') ?></th>
                    <th><?= $this->Paginator->sort('source', 'Table') ?></th>
                    <th><?= $this->Paginator->sort('primary_key', 'Record ID') ?></th>
                    <th><?= $this->Paginator->sort('user_id', 'Submitted By') ?></th>
                    <th><?= $this->Paginator->sort('status') ?></th>
                    <th><?= $this->Paginator->sort('created') ?></th>
                    <th class="actions"><?= __('Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bouncerRecords as $bouncerRecord) { ?>
                    <tr>
                        <td><?= $this->Number->format($bouncerRecord->id) ?></td>
                        <td><?= h($bouncerRecord->source) ?></td>
                        <td>
                            <?php if ($bouncerRecord->primary_key) { ?>
                                <?= $this->Number->format($bouncerRecord->primary_key) ?>
                                <span class="badge bg-info">Edit</span>
                            <?php } else { ?>
                                <span class="badge bg-success">New</span>
                            <?php } ?>
                        </td>
                        <td><?= $this->Number->format($bouncerRecord->user_id) ?></td>
                        <td>
                            <?php
                            $statusClass = match ($bouncerRecord->status) {
                                'pending' => 'warning',
                                'approved' => 'success',
                                'rejected' => 'danger',
                                'superseded' => 'secondary',
                                default => 'secondary',
                            };
                            ?>
                            <span class="badge bg-<?= $statusClass ?>"><?= h($bouncerRecord->status) ?></span>
                        </td>
                        <td><?= h($bouncerRecord->created) ?></td>
                        <td class="actions">
                            <?= $this->Html->link(__('Review'), ['action' => 'view', $bouncerRecord->id], ['class' => 'btn btn-sm btn-primary']) ?>
                            <?php if ($bouncerRecord->isPending()) { ?>
                                <?= $this->Form->postLink(
                                    __('Approve'),
                                    ['action' => 'approve', $bouncerRecord->id],
                                    ['confirm' => __('Are you sure you want to approve this change?'), 'class' => 'btn btn-sm btn-success'],
                                ) ?>
                                <?= $this->Form->postLink(
                                    __('Reject'),
                                    ['action' => 'reject', $bouncerRecord->id],
                                    ['confirm' => __('Are you sure you want to reject this change?'), 'class' => 'btn btn-sm btn-danger'],
                                ) ?>
                            <?php } ?>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>

    <div class="paginator">
        <ul class="pagination">
            <?= $this->Paginator->first('<< ' . __('first')) ?>
            <?= $this->Paginator->prev('< ' . __('previous')) ?>
            <?= $this->Paginator->numbers() ?>
            <?= $this->Paginator->next(__('next') . ' >') ?>
            <?= $this->Paginator->last(__('last') . ' >>') ?>
        </ul>
        <p><?= $this->Paginator->counter(__('Page {{page}} of {{pages}}, showing {{current}} record(s) out of {{count}} total')) ?></p>
    </div>
</div>
