<?php
/**
 * @var \App\View\AppView $this
 * @var \Bouncer\Model\Entity\BouncerRecord $bouncerRecord
 * @var \Cake\Datasource\EntityInterface $currentRecord
 * @var array<string, mixed> $conflict
 */

$formatValue = function ($value, bool $preserveNewlines = true) {
    if ($value === null) {
        return '<em class="text-muted">null</em>';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_array($value) || is_object($value)) {
        return '<pre class="mb-0 small">' . h(json_encode($value, JSON_PRETTY_PRINT)) . '</pre>';
    }
    $str = (string)$value;
    if ($preserveNewlines && str_contains($str, "\n")) {
        return nl2br(h($str));
    }

    return h($str);
};

$hasAutoMerged = !empty($conflict['autoMerged']);
$hasConflicts = !empty($conflict['conflicts']);
?>
<div class="bouncer resolve content">
    <h1><?= __('Resolve Conflicts') ?></h1>

    <?php if ($hasConflicts) { ?>
        <div class="alert alert-danger">
            <strong><?= __('Manual Resolution Required') ?></strong>
            <?= __('Some fields have conflicting changes that could not be auto-merged. Please review and choose the correct value for each conflicting field.') ?>
        </div>
    <?php } ?>

    <?php if ($hasAutoMerged) { ?>
        <div class="alert alert-success">
            <strong><?= __('Auto-Merged Successfully') ?></strong>
            <?= __('Some fields were automatically merged because the changes did not overlap.') ?>
        </div>
    <?php } ?>

    <div class="card mb-3">
        <div class="card-header">
            <strong><?= __('Record: {0} #{1}', h($bouncerRecord->source), $bouncerRecord->primary_key) ?></strong>
        </div>
    </div>

    <?= $this->Form->create(null, ['id' => 'resolve-form']) ?>

    <?php if ($hasAutoMerged) { ?>
        <div class="card mb-3">
            <div class="card-header bg-success text-white">
                <strong><?= __('Auto-Merged Fields') ?></strong>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="table-light">
                            <tr>
                                <th class="bouncer-col-w-12"><?= __('Field') ?></th>
                                <th class="bouncer-col-w-18"><?= __('Original') ?></th>
                                <th class="bouncer-col-w-18"><?= __('Current') ?></th>
                                <th class="bouncer-col-w-18"><?= __('Proposed') ?></th>
                                <th class="bouncer-col-w-18"><?= __('Merged Result') ?></th>
                                <th class="bouncer-col-w-16"><?= __('Changes') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($conflict['autoMerged'] as $field => $data) { ?>
                                <tr class="table-success">
                                    <td>
                                        <strong><?= h($field) ?></strong>
                                        <br><span class="badge bg-success"><?= __('AUTO-MERGED') ?></span>
                                    </td>
                                    <td class="text-muted">
                                        <div class="small text-break"><?= $formatValue($data['original']) ?></div>
                                    </td>
                                    <td class="text-info">
                                        <div class="small text-break"><?= $formatValue($data['current']) ?></div>
                                    </td>
                                    <td class="text-primary">
                                        <div class="small text-break"><?= $formatValue($data['proposed']) ?></div>
                                    </td>
                                    <td class="bg-light">
                                        <?= $this->Form->hidden("merged.{$field}", ['value' => $data['result']]) ?>
                                        <div class="small text-break"><strong><?= $formatValue($data['result']) ?></strong></div>
                                    </td>
                                    <td class="small">
                                        <?php if ($data['currentChanges']) { ?>
                                            <div class="text-info mb-1">
                                                <strong><?= __('Current:') ?></strong>
                                                <?php foreach ($data['currentChanges'] as $change) { ?>
                                                    <div><?= h($change) ?></div>
                                                <?php } ?>
                                            </div>
                                        <?php } ?>
                                        <?php if ($data['proposedChanges']) { ?>
                                            <div class="text-primary">
                                                <strong><?= __('Proposed:') ?></strong>
                                                <?php foreach ($data['proposedChanges'] as $change) { ?>
                                                    <div><?= h($change) ?></div>
                                                <?php } ?>
                                            </div>
                                        <?php } ?>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php } ?>

    <?php if ($hasConflicts) { ?>
        <div class="card mb-3">
            <div class="card-header bg-danger text-white">
                <strong><?= __('Conflicting Fields - Manual Resolution Required') ?></strong>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="table-light">
                            <tr>
                                <th class="bouncer-col-w-15"><?= __('Field') ?></th>
                                <th class="bouncer-col-w-20"><?= __('Original') ?></th>
                                <th class="bouncer-col-w-20"><?= __('Current') ?></th>
                                <th class="bouncer-col-w-20"><?= __('Proposed') ?></th>
                                <th class="bouncer-col-w-25"><?= __('Choose Value') ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($conflict['conflicts'] as $field => $data) {
                                $isMultiline = is_string($data['proposed']) && str_contains($data['proposed'], "\n");
                                $inputType = $isMultiline ? 'textarea' : 'text';
                            ?>
                                <tr class="table-danger">
                                    <td>
                                        <strong><?= h($field) ?></strong>
                                        <br><span class="badge bg-danger"><?= __('CONFLICT') ?></span>
                                    </td>
                                    <td class="text-muted">
                                        <div class="small text-break"><?= $formatValue($data['original']) ?></div>
                                    </td>
                                    <td class="text-info">
                                        <div class="small text-break"><?= $formatValue($data['current']) ?></div>
                                    </td>
                                    <td class="text-primary">
                                        <div class="small text-break"><?= $formatValue($data['proposed']) ?></div>
                                    </td>
                                    <td>
                                        <?= $this->Form->control("merged.{$field}", [
                                            'type' => $inputType,
                                            'label' => false,
                                            'value' => $data['proposed'],
                                            'class' => 'form-control form-control-sm',
                                            'id' => 'merged-' . $field,
                                            'rows' => $isMultiline ? 4 : null,
                                        ]) ?>
                                        <div class="btn-group btn-group-sm mt-1" role="group">
                                            <button type="button" class="btn btn-outline-info use-value"
                                                    data-field="<?= h($field) ?>"
                                                    data-value="<?= h($data['current']) ?>">
                                                <?= __('Use Current') ?>
                                            </button>
                                            <button type="button" class="btn btn-outline-primary use-value"
                                                    data-field="<?= h($field) ?>"
                                                    data-value="<?= h($data['proposed']) ?>">
                                                <?= __('Use Proposed') ?>
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            <?php } ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php } ?>

    <?php
    // Add hidden fields for non-conflicting, non-auto-merged fields
    // Only include fields that were in the original proposal to avoid noise
    $proposedFields = array_keys($conflict['proposed']);
    foreach ($proposedFields as $field) {
        if (in_array($field, ['created', 'modified', 'id', '_delete'], true)) {
            continue;
        }
        if (isset($conflict['conflicts'][$field]) || isset($conflict['autoMerged'][$field])) {
            continue;
        }
        $value = $conflict['merged'][$field] ?? $conflict['proposed'][$field] ?? null;
        echo $this->Form->hidden("merged.{$field}", ['value' => $value]);
    }
    ?>

    <div class="card">
        <div class="card-body">
            <div class="d-flex justify-content-between">
                <?= $this->Html->link(__('Cancel'), ['action' => 'view', $bouncerRecord->id], ['class' => 'btn btn-secondary']) ?>
                <?= $this->Form->button(__('Save Merged Changes'), ['class' => 'btn btn-primary']) ?>
            </div>
        </div>
    </div>

    <?= $this->Form->end() ?>
</div>

<?php
$this->Html->scriptBlock("
document.querySelectorAll('.use-value').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var field = this.dataset.field;
        var value = this.dataset.value;
        var input = document.getElementById('merged-' + field);
        if (input) {
            input.value = value;
        }
    });
});
", ['block' => true]);
?>
