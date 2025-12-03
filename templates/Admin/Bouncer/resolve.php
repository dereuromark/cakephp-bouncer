<?php
/**
 * @var \App\View\AppView $this
 * @var \Bouncer\Model\Entity\BouncerRecord $bouncerRecord
 * @var \Cake\Datasource\EntityInterface $currentRecord
 * @var array $conflict
 */

$formatValue = function ($value) {
    if ($value === null) {
        return 'null';
    }
    if (is_bool($value)) {
        return $value ? 'true' : 'false';
    }
    if (is_array($value) || is_object($value)) {
        return json_encode($value);
    }

    return (string)$value;
};
?>
<div class="bouncer resolve content">
    <h1><?= __('Resolve Conflicts') ?></h1>

    <div class="alert alert-warning">
        <strong><?= __('Conflict Detected!') ?></strong>
        <?= __('This record was modified after the draft was created, and some of the same fields were changed. Please choose which value to keep for each conflicting field.') ?>
    </div>

    <div class="card mb-3">
        <div class="card-header">
            <strong><?= __('Record: {0} #{1}', h($bouncerRecord->source), $bouncerRecord->primary_key) ?></strong>
        </div>
    </div>

    <?= $this->Form->create(null, ['id' => 'resolve-form']) ?>

    <div class="card mb-3">
        <div class="card-header">
            <strong><?= __('3-Way Merge') ?></strong>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-bordered table-sm">
                    <thead class="table-light">
                        <tr>
                            <th style="width: 15%"><?= __('Field') ?></th>
                            <th style="width: 20%"><?= __('Original') ?><br><small class="text-muted"><?= __('When draft created') ?></small></th>
                            <th style="width: 20%"><?= __('Current') ?><br><small class="text-muted"><?= __('Live version now') ?></small></th>
                            <th style="width: 20%"><?= __('Proposed') ?><br><small class="text-muted"><?= __('Your changes') ?></small></th>
                            <th style="width: 25%"><?= __('Merged Result') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $allFields = array_unique(array_merge(
                            array_keys($conflict['original']),
                            array_keys($conflict['current']),
                            array_keys($conflict['proposed']),
                        ));
                        sort($allFields);

                        foreach ($allFields as $field) {
                            if (in_array($field, ['created', 'modified', 'id', '_delete'], true)) {
                                continue;
                            }

                            $originalValue = $conflict['original'][$field] ?? null;
                            $currentValue = $conflict['current'][$field] ?? null;
                            $proposedValue = $conflict['proposed'][$field] ?? null;
                            $hasConflict = isset($conflict['conflicts'][$field]);

                            // Determine default merged value
                            $defaultMerged = $proposedValue ?? $currentValue ?? $originalValue;

                            $rowClass = $hasConflict ? 'table-danger' : '';
                            ?>
                            <tr class="<?= $rowClass ?>">
                                <td>
                                    <strong><?= h($field) ?></strong>
                                    <?php if ($hasConflict) { ?>
                                        <br><span class="badge bg-danger"><?= __('CONFLICT') ?></span>
                                    <?php } ?>
                                </td>
                                <td class="text-muted">
                                    <code class="small"><?= h($formatValue($originalValue)) ?></code>
                                </td>
                                <td class="text-info">
                                    <code class="small"><?= h($formatValue($currentValue)) ?></code>
                                </td>
                                <td class="text-success">
                                    <code class="small"><?= h($formatValue($proposedValue)) ?></code>
                                </td>
                                <td>
                                    <?php if ($hasConflict) { ?>
                                        <?= $this->Form->control("merged.{$field}", [
                                            'label' => false,
                                            'value' => $proposedValue,
                                            'class' => 'form-control form-control-sm',
                                            'id' => 'merged-' . $field,
                                        ]) ?>
                                        <div class="btn-group btn-group-sm mt-1" role="group">
                                            <button type="button" class="btn btn-outline-secondary use-value"
                                                    data-field="<?= h($field) ?>"
                                                    data-value="<?= h($currentValue) ?>">
                                                <?= __('Use Current') ?>
                                            </button>
                                            <button type="button" class="btn btn-outline-secondary use-value"
                                                    data-field="<?= h($field) ?>"
                                                    data-value="<?= h($proposedValue) ?>">
                                                <?= __('Use Proposed') ?>
                                            </button>
                                        </div>
                                    <?php } else { ?>
                                        <?= $this->Form->hidden("merged.{$field}", ['value' => $defaultMerged]) ?>
                                        <code class="small"><?= h($formatValue($defaultMerged)) ?></code>
                                    <?php } ?>
                                </td>
                            </tr>
                        <?php } ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

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
// Add helper for formatting values
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

