<?php

declare(strict_types=1);

namespace Bouncer\View\Helper;

use Bouncer\Lib\DiffLib;
use Bouncer\Model\Entity\BouncerRecord;
use Cake\Datasource\EntityInterface;
use Cake\I18n\DateTime;
use Cake\View\Helper;
use Jfcherng\Diff\DiffHelper;

/**
 * Bouncer helper for displaying bouncer record changes in a human-readable format
 *
 * Uses jfcherng/php-diff for word-level diff rendering if available,
 * falls back to DiffLib (sebastian/diff based) otherwise.
 *
 * @property \Cake\View\Helper\HtmlHelper $Html
 */
class BouncerHelper extends Helper
{
    /**
     * Helpers to load
     *
     * @var array
     */
    protected array $helpers = ['Html'];

    /**
     * Default configuration.
     *
     * @var array<string, mixed>
     */
    protected array $_defaultConfig = [
        'differOptions' => [
            'context' => 2,
            'ignoreCase' => false,
            'ignoreWhitespace' => false,
        ],
        'rendererOptions' => [
            'detailLevel' => 'word',
            'showHeader' => false,
            'lineNumbers' => true,
        ],
    ];

    /**
     * Whether jfcherng/php-diff is available.
     *
     * @var bool|null
     */
    protected ?bool $_hasJfcherng = null;

    /**
     * Check if jfcherng/php-diff library is available.
     *
     * @return bool
     */
    protected function hasJfcherngDiff(): bool
    {
        if ($this->_hasJfcherng === null) {
            $this->_hasJfcherng = class_exists(DiffHelper::class);
        }

        return $this->_hasJfcherng;
    }

    /**
     * Render diff using jfcherng/php-diff.
     *
     * @param string $old Old text
     * @param string $new New text
     * @param string $renderer 'SideBySide' or 'Inline'
     *
     * @return string HTML output
     */
    protected function renderJfcherngDiff(string $old, string $new, string $renderer): string
    {
        /** @var array<string, mixed> $differOptions */
        $differOptions = $this->getConfig('differOptions');
        /** @var array<string, mixed> $rendererOptions */
        $rendererOptions = $this->getConfig('rendererOptions');

        return DiffHelper::calculate($old, $new, $renderer, $differOptions, $rendererOptions);
    }

    /**
     * Render diff using fallback DiffLib.
     *
     * @param string $old Old text
     * @param string $new New text
     * @param string $renderer 'SideBySide' or 'Inline'
     *
     * @return string HTML output
     */
    protected function renderFallbackDiff(string $old, string $new, string $renderer): string
    {
        $diffLib = new DiffLib();
        /** @var array<string, mixed> $differOptions */
        $differOptions = $this->getConfig('differOptions');
        $diffLib->contextLines = $differOptions['context'] ?? 3;

        if ($renderer === 'SideBySide') {
            return $diffLib->compareSideBySide($old, $new);
        }

        return $diffLib->compare($old, $new);
    }

    /**
     * Render a diff between two strings.
     *
     * @param string $old Old text
     * @param string $new New text
     * @param string $renderer 'SideBySide' or 'Inline'
     *
     * @return string HTML output
     */
    protected function renderDiff(string $old, string $new, string $renderer): string
    {
        // Check for whitespace-only changes first - use DiffLib for special rendering
        $diffLib = new DiffLib();
        if ($diffLib->isWhitespaceOnlyChange($old, $new)) {
            return $diffLib->renderWhitespaceChange($old, $new);
        }

        if ($this->hasJfcherngDiff()) {
            return $this->renderJfcherngDiff($old, $new, $renderer);
        }

        return $this->renderFallbackDiff($old, $new, $renderer);
    }

    /**
     * @var int
     * @deprecated Use $_defaultConfig['differOptions']['context'] instead
     */
    protected int $contextLines = 2;

    /**
     * Calculate diffs between bouncer record and current/original record.
     *
     * For pending records: compares current live record vs proposed changes.
     * For processed records (approved/rejected): compares original_data vs proposed data.
     *
     * @param \Bouncer\Model\Entity\BouncerRecord $bouncerRecord
     * @param \Cake\Datasource\EntityInterface|null $currentRecord
     *
     * @return array<string, array<string, mixed>>
     */
    public function calculateDiffs(BouncerRecord $bouncerRecord, ?EntityInterface $currentRecord): array
    {
        if (!$bouncerRecord->isEditProposal()) {
            return [];
        }

        $proposedData = $bouncerRecord->getData();

        // For non-pending records, compare original_data vs proposed data
        // (current record may have changed or been merged already)
        if (!$bouncerRecord->isPending()) {
            $baseData = $bouncerRecord->getOriginalData();
            if (!$baseData) {
                return [];
            }
        } else {
            // For pending records, compare against current live record
            if (!$currentRecord) {
                return [];
            }
            $baseData = $currentRecord->toArray();
            // Use merged data if available (for stale records with auto-merge)
            $proposedData = $bouncerRecord->getMergedData();
        }

        $allFields = array_unique(array_merge(array_keys($baseData), array_keys($proposedData)));
        sort($allFields);

        $diffs = [];
        foreach ($allFields as $field) {
            if (in_array($field, ['created', 'modified', 'id'], true) || str_starts_with($field, '_')) {
                continue;
            }
            if (!array_key_exists($field, $proposedData)) {
                continue;
            }

            $baseValue = $baseData[$field] ?? null;
            $proposedValue = $proposedData[$field] ?? null;

            $baseStr = $this->valueToString($baseValue);
            $proposedStr = $this->valueToString($proposedValue);

            if ($baseStr === $proposedStr) {
                continue;
            }

            $isLongText = strlen($baseStr) > 100 || strlen($proposedStr) > 100
                || str_contains($baseStr, "\n") || str_contains($proposedStr, "\n");

            $diffs[$field] = [
                'baseStr' => $baseStr,
                'proposedStr' => $proposedStr,
                'isLongText' => $isLongText,
                'inline' => $isLongText ? $this->renderDiff($baseStr, $proposedStr, 'Inline') : null,
                'sideBySide' => $isLongText ? $this->renderDiff($baseStr, $proposedStr, 'SideBySide') : null,
            ];
        }

        return $diffs;
    }

    /**
     * Render inline diff view for all fields.
     *
     * @param array<string, array<string, mixed>> $diffs Pre-calculated diffs
     *
     * @return string HTML output
     */
    public function diffInline(array $diffs): string
    {
        if (!$diffs) {
            return '<p class="text-muted">' . __('No changes detected') . '</p>';
        }

        $output = '';
        foreach ($diffs as $field => $diff) {
            $output .= '<div class="card mb-3">';
            $output .= '<div class="card-header bg-light py-2"><strong>' . h($field) . '</strong></div>';
            $output .= '<div class="card-body p-2">';

            if ($diff['isLongText']) {
                $output .= $diff['inline'];
            } else {
                $output .= $this->renderSimpleDiff($diff['baseStr'], $diff['proposedStr']);
            }

            $output .= '</div></div>';
        }

        return $output;
    }

    /**
     * Render side-by-side diff view for all fields.
     *
     * @param array<string, array<string, mixed>> $diffs Pre-calculated diffs
     *
     * @return string HTML output
     */
    public function diffSideBySide(array $diffs): string
    {
        if (!$diffs) {
            return '<p class="text-muted">' . __('No changes detected') . '</p>';
        }

        $output = '';
        foreach ($diffs as $field => $diff) {
            $output .= '<div class="card mb-3">';
            $output .= '<div class="card-header bg-light py-2"><strong>' . h($field) . '</strong></div>';
            $output .= '<div class="card-body p-2">';

            if ($diff['isLongText']) {
                $output .= $diff['sideBySide'];
            } else {
                $output .= $this->renderSimpleDiff($diff['baseStr'], $diff['proposedStr']);
            }

            $output .= '</div></div>';
        }

        return $output;
    }

    /**
     * Render a simple side-by-side diff for short values.
     *
     * @param string $baseStr
     * @param string $proposedStr
     *
     * @return string HTML output
     */
    protected function renderSimpleDiff(string $baseStr, string $proposedStr): string
    {
        $output = '<div class="row">';
        $output .= '<div class="col-md-6">';
        $output .= '<span class="text-muted">' . __('Before:') . '</span>';
        $output .= '<div class="p-2 bg-light border rounded"><del>' . h($baseStr) . '</del></div>';
        $output .= '</div>';
        $output .= '<div class="col-md-6">';
        $output .= '<span class="text-muted">' . __('After:') . '</span>';
        $output .= '<div class="p-2 bg-warning border rounded"><ins><strong>' . h($proposedStr) . '</strong></ins></div>';
        $output .= '</div>';
        $output .= '</div>';

        return $output;
    }

    /**
     * Render new record data table.
     *
     * @param \Bouncer\Model\Entity\BouncerRecord $bouncerRecord
     *
     * @return string HTML output
     */
    public function newRecordTable(BouncerRecord $bouncerRecord): string
    {
        $data = $bouncerRecord->getData();

        $output = '<h5>' . __('New Record Data') . '</h5>';
        $output .= '<table class="table table-bordered">';
        $output .= '<thead><tr><th>' . __('Field') . '</th><th>' . __('Value') . '</th></tr></thead>';
        $output .= '<tbody>';

        foreach ($data as $field => $value) {
            if (in_array($field, ['created', 'modified'], true) || str_starts_with($field, '_')) {
                continue;
            }
            $output .= '<tr>';
            $output .= '<td><strong>' . h($field) . '</strong></td>';
            $output .= '<td>' . $this->formatValue($value) . '</td>';
            $output .= '</tr>';
        }

        $output .= '</tbody></table>';

        return $output;
    }

    /**
     * Render raw JSON data details element.
     *
     * @param \Bouncer\Model\Entity\BouncerRecord $bouncerRecord
     *
     * @return string HTML output
     */
    public function rawJsonDetails(BouncerRecord $bouncerRecord): string
    {
        $output = '<details class="mt-3">';
        $output .= '<summary><strong>' . __('Raw JSON Data') . '</strong></summary>';
        $output .= '<pre class="bg-light p-3 mt-2"><code>';
        $output .= h(json_encode($bouncerRecord->getData(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $output .= '</code></pre></details>';

        return $output;
    }

    /**
     * Render status badge.
     *
     * @param string $status
     *
     * @return string HTML badge
     */
    public function statusBadge(string $status): string
    {
        $badges = [
            'pending' => 'bg-warning',
            'approved' => 'bg-success',
            'rejected' => 'bg-danger',
        ];

        $class = $badges[$status] ?? 'bg-secondary';

        return '<span class="badge ' . $class . '">' . h($status) . '</span>';
    }

    /**
     * Render record type badge.
     *
     * @param \Bouncer\Model\Entity\BouncerRecord $bouncerRecord
     *
     * @return string HTML badge
     */
    public function recordTypeBadge(BouncerRecord $bouncerRecord): string
    {
        if ($bouncerRecord->isNewRecordProposal()) {
            return '<span class="badge bg-success">' . __('New Record') . '</span>';
        }

        return '<span class="badge bg-info">' . __('Edit to Record #{0}', $bouncerRecord->primary_key) . '</span>';
    }

    /**
     * Get CSS styles for diff rendering.
     *
     * Includes jfcherng/php-diff built-in styles (if available) plus custom overrides.
     *
     * @return string CSS styles
     */
    public function diffStyles(): string
    {
        $libraryStyles = '';
        if ($this->hasJfcherngDiff()) {
            $libraryStyles = DiffHelper::getStyleSheet();
        }

        $customStyles = <<<'CSS'
.diff-wrapper { width: 100%; border-collapse: collapse; font-family: monospace; font-size: 13px; }
.diff-wrapper th, .diff-wrapper td { padding: 4px 8px; border: 1px solid #dee2e6; vertical-align: top; }
.diff-wrapper .line-num { width: 40px; background: #f8f9fa; color: #6c757d; text-align: right; user-select: none; }
.diff-wrapper .sign { width: 20px; text-align: center; font-weight: bold; }
.diff-wrapper tr.unchanged td { background: #f8f9fa; }
.diff-wrapper tr.added td { background: #e6ffec; }
.diff-wrapper tr.removed td { background: #ffebe9; }
.diff-wrapper tr.changed td { background: #fef6d9; }
.diff-wrapper tr.separator td { background: #f0f0f0; font-style: italic; }
.diff-wrapper ins { background: #94f094; text-decoration: none; padding: 1px 2px; font-weight: bold; }
.diff-wrapper del { background: #f09494; text-decoration: none; padding: 1px 2px; font-weight: bold; }
.diff-wrapper .old { background-color: #ffebe9; }
.diff-wrapper .new { background-color: #e6ffec; }
/* Side-by-side specific */
.diff-side-by-side th:nth-child(2), .diff-side-by-side td:nth-child(2) { width: 45%; }
.diff-side-by-side th:nth-child(4), .diff-side-by-side td:nth-child(4) { width: 45%; }
/* Whitespace-only changes */
.diff-wrapper .empty-line { font-weight: bold; }
.diff-whitespace-change ins.empty-line { background-color: #acf2bd; color: #155724; text-decoration: none; padding: 1px 3px; border-radius: 2px; }
.diff-whitespace-change del.empty-line { background-color: #fdb8c0; color: #721c24; text-decoration: line-through; padding: 1px 3px; border-radius: 2px; }
CSS;

        return '<style>' . $libraryStyles . "\n" . $customStyles . '</style>';
    }

    /**
     * Render diff view toggle buttons.
     *
     * @return string HTML buttons
     */
    public function diffToggleButtons(): string
    {
        return <<<HTML
<div class="btn-group btn-group-sm" role="group">
    <button type="button" class="btn btn-outline-secondary active" id="btn-inline-diff">Inline</button>
    <button type="button" class="btn btn-outline-secondary" id="btn-side-diff">Side-by-side</button>
</div>
HTML;
    }

    /**
     * Get JavaScript for diff view toggle.
     *
     * @return string JavaScript code
     */
    public function diffToggleScript(): string
    {
        return <<<JS
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
JS;
    }

    /**
     * Convert any value to a comparable string.
     *
     * @param mixed $value
     *
     * @return string
     */
    protected function valueToString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof DateTime) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string)$value;
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }

        if (is_scalar($value)) {
            return (string)$value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
    }

    /**
     * Format a value for display.
     *
     * @param mixed $value
     *
     * @return string HTML formatted value
     */
    public function formatValue(mixed $value): string
    {
        if ($value === null) {
            return '<em class="text-muted">null</em>';
        }

        if (is_bool($value)) {
            return $value
                ? '<span class="badge bg-success">true</span>'
                : '<span class="badge bg-secondary">false</span>';
        }

        if (is_array($value)) {
            return '<pre class="mb-0"><code>' . h(json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '</code></pre>';
        }

        if (is_string($value) && strlen($value) > 100) {
            return '<div class="text-break">' . nl2br(h($value)) . '</div>';
        }

        return h((string)$value);
    }
}
