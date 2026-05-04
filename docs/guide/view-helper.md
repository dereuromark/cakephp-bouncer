# Bouncer View Helper

The `BouncerHelper` renders proposals — diff output, status badges, value
formatting — in templates. The bundled admin UI uses it heavily; you can
also use it in your own dashboards or "your pending changes" pages.

## Loading

```php
// Once, in src/View/AppView.php
public function initialize(): void
{
    parent::initialize();
    $this->loadHelper('Bouncer.Bouncer');
}
```

…or per-controller:

```php
public function beforeRender(\Cake\Event\EventInterface $event)
{
    parent::beforeRender($event);
    $this->viewBuilder()->addHelper('Bouncer.Bouncer');
}
```

## Configuration

```php
$this->loadHelper('Bouncer.Bouncer', [
    'differOptions' => [
        'context'          => 3,     // context lines around changes
        'ignoreCase'       => false,
        'ignoreWhitespace' => false,
    ],
    'rendererOptions' => [
        'detailLevel' => 'word',     // 'word' | 'char' | 'line'
        'showHeader'  => false,
        'lineNumbers' => true,
    ],
]);
```

For most apps the defaults are fine — override only when you've decided
the diff output is too noisy or too coarse for your data.

## Enhanced diff rendering

If `jfcherng/php-diff` is in your `composer.json`, the helper uses it for
word-level diffs with better visual styling. Without it, the helper falls
back to `sebastian/diff` (character-level, dependency-free).

```bash
composer require jfcherng/php-diff
```

Recommended whenever your proposals contain free-text fields longer than a
sentence — the difference between word-level and character-level is the
difference between "readable" and "headache."

## Method reference

### Diff rendering

| Method | Returns |
|---|---|
| `calculateDiffs($bouncerRecord, $currentRecord)` | array of per-field diff payloads |
| `diffInline($diffs)` | unified inline diff HTML |
| `diffSideBySide($diffs)` | two-column diff HTML |
| `diffStyles()` | `<style>` block — call once in the layout |
| `diffToggleButtons()` | inline/side-by-side toggle buttons |
| `diffToggleScript()` | `<script>` for the toggle buttons |

### Tables and badges

| Method | Returns |
|---|---|
| `newRecordTable($bouncerRecord)` | full-width field/value table for proposed *new* records (no diff to render) |
| `statusBadge($status)` | colored badge — `pending` / `approved` / `rejected` / `superseded` |
| `recordTypeBadge($bouncerRecord)` | "New record" / "Edit" / "Delete" badge based on the proposal type |
| `formatValue($value)` | one cell of HTML — handles `null`, bools, arrays, long strings |

## Example: "your pending changes" page

```php
<?php
$this->loadHelper('Bouncer.Bouncer');
echo $this->Bouncer->diffStyles();
?>

<h2>Your pending proposals</h2>

<?php foreach ($pendingRecords as $record): ?>
    <div class="card mb-4">
        <div class="card-header d-flex gap-2">
            <?= $this->Bouncer->recordTypeBadge($record) ?>
            <?= $this->Bouncer->statusBadge($record->status) ?>
            <span class="ms-auto text-muted small">
                <?= $record->created->nice() ?> · <?= h($record->source) ?>
            </span>
        </div>
        <div class="card-body">
            <?php if ($record->primary_key): ?>
                <?php $diffs = $this->Bouncer->calculateDiffs($record, $currentByKey[$record->primary_key]) ?>
                <?= $this->Bouncer->diffSideBySide($diffs) ?>
            <?php else: ?>
                <?= $this->Bouncer->newRecordTable($record) ?>
            <?php endif ?>

            <?php if ($record->reason): ?>
                <p class="mt-3"><strong>Reviewer note:</strong> <?= h($record->reason) ?></p>
            <?php endif ?>
        </div>
    </div>
<?php endforeach ?>

<?= $this->Bouncer->diffToggleScript() ?>
```

Output is Bootstrap 5 markup (cards, badges, tables). If you use a
different framework, wrap the helper output or copy the pieces you need
into a project-local helper.
