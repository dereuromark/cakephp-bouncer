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
    <h1><?= __d('bouncer', 'Pending Approvals') ?></h1>

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
                        'empty' => 'All Sources',
                        'default' => $source,
                        'label' => 'Source',
                    ]) ?>
                </div>
                <div class="col-md-3">
                    <?= $this->Form->control('user_id', [
                        'type' => 'text',
                        'label' => 'User ID',
                        'default' => $userId,
                        'placeholder' => 'Search by user ID',
                        'class' => 'form-control',
                    ]) ?>
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <?= $this->Form->button(__d('bouncer', 'Filter'), ['class' => 'btn btn-primary']) ?>
                    <?php if ($this->request->getQueryParams()) { ?>
                        <?= $this->Html->link(__d('bouncer', 'Clear'), ['action' => 'index'], ['class' => 'btn btn-secondary']) ?>
                    <?php } ?>
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
                    <th><?= $this->Paginator->sort('source', 'Source') ?></th>
                    <th><?= $this->Paginator->sort('primary_key', 'Record') ?></th>
                    <th><?= $this->Paginator->sort('user_id', 'Submitted By') ?></th>
                    <th><?= $this->Paginator->sort('status') ?></th>
                    <th><?= $this->Paginator->sort('created') ?></th>
                    <th class="actions"><?= __d('bouncer', 'Actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bouncerRecords as $bouncerRecord) { ?>
                    <tr>
                        <td><?= $this->Number->format($bouncerRecord->id) ?></td>
                        <td><code><?= h($bouncerRecord->source) ?></code></td>
                        <td>
                            <?php if ($bouncerRecord->isDeleteProposal()) { ?>
                                <?= $this->Bouncer->formatRecord($bouncerRecord->source, $bouncerRecord->primary_key) ?>
                                <span class="badge bg-danger">Delete</span>
                            <?php } elseif ($bouncerRecord->primary_key) { ?>
                                <?= $this->Bouncer->formatRecord($bouncerRecord->source, $bouncerRecord->primary_key) ?>
                                <span class="badge bg-info">Edit</span>
                            <?php } else { ?>
                                <span class="badge bg-success">New</span>
                            <?php } ?>
                        </td>
                        <td><?= $this->Bouncer->formatUser($bouncerRecord->user_id, $bouncerRecord->user_display) ?></td>
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
                            <?= $this->Html->link(__d('bouncer', 'Review'), ['action' => 'view', $bouncerRecord->id], ['class' => 'btn btn-sm btn-' . ($bouncerRecord->isPending() ? 'primary' : 'secondary')]) ?>
                        </td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>

    <?= $this->element('Bouncer.pagination') ?>
</div>
