<?php
/**
 * @var \App\View\AppView $this
 * @var \Bouncer\Model\Entity\BouncerRecord $bouncerRecord
 * @var \Cake\Datasource\EntityInterface|null $currentRecord
 */

use Bouncer\Lib\DiffLib;
use Cake\I18n\DateTime;

/**
 * Convert any value to a comparable string.
 *
 * @param mixed $value
 * @return string
 */
$valueToString = function (mixed $value): string {
    if ($value === null) {
        return '';
    }
    if ($value instanceof DateTime) {
        return $value->format('Y-m-d H:i:s');
    }
    if (is_object($value) && method_exists($value, '__toString')) {
        return (string)$value;
    }
    if (is_scalar($value)) {
        return (string)$value;
    }

    return json_encode($value) ?: '';
};

// Pre-calculate diffs for long text fields
$diffs = [];
if ($bouncerRecord->isEditProposal() && $currentRecord) {
    $proposedData = $bouncerRecord->getData();
    $currentData = $currentRecord->toArray();
    $allFields = array_unique(array_merge(array_keys($currentData), array_keys($proposedData)));
    sort($allFields);

    $diffLib = new DiffLib();
    $diffLib->contextLines = 2;

    foreach ($allFields as $field) {
        if (in_array($field, ['created', 'modified', 'id']) || str_starts_with($field, '_')) {
            continue;
        }
        if (!array_key_exists($field, $proposedData)) {
            continue;
        }

        $currentValue = $currentData[$field] ?? null;
        $proposedValue = $proposedData[$field] ?? null;

        $currentStr = $valueToString($currentValue);
        $proposedStr = $valueToString($proposedValue);

        if ($currentStr === $proposedStr) {
            continue;
        }

        $isLongText = strlen($currentStr) > 100 || strlen($proposedStr) > 100
            || str_contains($currentStr, "\n") || str_contains($proposedStr, "\n");

        $diffs[$field] = [
            'currentStr' => $currentStr,
            'proposedStr' => $proposedStr,
            'isLongText' => $isLongText,
            'inline' => $isLongText ? $diffLib->compare($currentStr, $proposedStr) : null,
            'sideBySide' => $isLongText ? $diffLib->compareSideBySide($currentStr, $proposedStr) : null,
        ];
    }
}
?>
<style>
.diff-wrapper { width: 100%; border-collapse: collapse; font-family: monospace; font-size: 13px; }
.diff-wrapper th, .diff-wrapper td { padding: 4px 8px; border: 1px solid #dee2e6; vertical-align: top; }
.diff-wrapper .line-num { width: 40px; background: #f8f9fa; color: #6c757d; text-align: right; }
.diff-wrapper .sign { width: 20px; text-align: center; font-weight: bold; }
.diff-wrapper tr.unchanged td { background: #fff; }
.diff-wrapper tr.added td { background: #d4edda; }
.diff-wrapper tr.removed td { background: #f8d7da; }
.diff-wrapper tr.separator td { background: #f8f9fa; font-style: italic; }
.diff-wrapper ins { background: #c3e6cb; text-decoration: none; padding: 1px 2px; }
.diff-wrapper del { background: #f5c6cb; text-decoration: line-through; padding: 1px 2px; }
/* Side-by-side specific */
.diff-side-by-side th:nth-child(2), .diff-side-by-side td:nth-child(2) { width: 45%; }
.diff-side-by-side th:nth-child(4), .diff-side-by-side td:nth-child(4) { width: 45%; }
.diff-side-by-side tr.changed td:nth-child(2) { background: #f8d7da; }
.diff-side-by-side tr.changed td:nth-child(4) { background: #d4edda; }
</style>
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
            <div class="btn-group btn-group-sm" role="group">
                <button type="button" class="btn btn-outline-secondary active" id="btn-inline-diff">Inline</button>
                <button type="button" class="btn btn-outline-secondary" id="btn-side-diff">Side-by-side</button>
            </div>
            <?php } ?>
        </div>
        <div class="card-body">
            <?php if ($bouncerRecord->isEditProposal() && $currentRecord) { ?>
                <div id="inline-diff-view">
                <?php foreach ($diffs as $field => $diff) { ?>
                    <div class="card mb-3">
                        <div class="card-header bg-light py-2">
                            <strong><?= h($field) ?></strong>
                        </div>
                        <div class="card-body p-2">
                            <?php if ($diff['isLongText']) { ?>
                                <?= $diff['inline'] ?>
                            <?php } else { ?>
                                <div class="row">
                                    <div class="col-md-6">
                                        <span class="text-muted"><?= __('Current:') ?></span>
                                        <div class="p-2 bg-light border rounded"><del><?= h($diff['currentStr']) ?></del></div>
                                    </div>
                                    <div class="col-md-6">
                                        <span class="text-muted"><?= __('Proposed:') ?></span>
                                        <div class="p-2 bg-warning border rounded"><ins><strong><?= h($diff['proposedStr']) ?></strong></ins></div>
                                    </div>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                <?php } ?>
                </div>
                <div id="side-diff-view" style="display: none;">
                <?php foreach ($diffs as $field => $diff) { ?>
                    <div class="card mb-3">
                        <div class="card-header bg-light py-2">
                            <strong><?= h($field) ?></strong>
                        </div>
                        <div class="card-body p-2">
                            <?php if ($diff['isLongText']) { ?>
                                <?= $diff['sideBySide'] ?>
                            <?php } else { ?>
                                <div class="row">
                                    <div class="col-md-6">
                                        <span class="text-muted"><?= __('Current:') ?></span>
                                        <div class="p-2 bg-light border rounded"><del><?= h($diff['currentStr']) ?></del></div>
                                    </div>
                                    <div class="col-md-6">
                                        <span class="text-muted"><?= __('Proposed:') ?></span>
                                        <div class="p-2 bg-warning border rounded"><ins><strong><?= h($diff['proposedStr']) ?></strong></ins></div>
                                    </div>
                                </div>
                            <?php } ?>
                        </div>
                    </div>
                <?php } ?>
                </div>
                <?php if (!$diffs) { ?>
                    <p class="text-muted"><?= __('No changes detected') ?></p>
                <?php } ?>
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
                            <?php if (in_array($field, ['created', 'modified']) || str_starts_with($field, '_')) {
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    const btnInline = document.getElementById('btn-inline-diff');
    const btnSide = document.getElementById('btn-side-diff');
    const inlineView = document.getElementById('inline-diff-view');
    const sideView = document.getElementById('side-diff-view');

    if (btnInline && btnSide) {
        btnInline.addEventListener('click', function() {
            inlineView.style.display = 'block';
            sideView.style.display = 'none';
            btnInline.classList.add('active');
            btnSide.classList.remove('active');
        });

        btnSide.addEventListener('click', function() {
            inlineView.style.display = 'none';
            sideView.style.display = 'block';
            btnSide.classList.add('active');
            btnInline.classList.remove('active');
        });
    }
});
</script>
