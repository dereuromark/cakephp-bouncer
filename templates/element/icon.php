<?php
/**
 * Icon element with fallbacks
 *
 * Supports Templating plugin's IconHelper when available,
 * falls back to Font Awesome HTML.
 *
 * @var \Cake\View\View $this
 * @var string $name Icon name
 * @var string|null $fallback Font Awesome class fallback (e.g., 'fas fa-list')
 * @var array $options Additional options for IconHelper
 * @var array $attributes HTML attributes
 */

$name = $name ?? '';
$fallback = $fallback ?? null;
$options = $options ?? [];
$attributes = $attributes ?? [];

// Try Templating plugin's Icon helper
if ($this->helpers()->has('Icon')) {
    echo $this->Icon->render($name, $options, $attributes);

    return;
}

// Font Awesome fallback mapping
$fontAwesomeMap = [
    'list' => 'fas fa-list',
    'clock' => 'fas fa-clock',
    'check-circle' => 'fas fa-check-circle',
    'times-circle' => 'fas fa-times-circle',
    'globe' => 'fas fa-globe',
    'shield-alt' => 'fas fa-shield-alt',
    'eye' => 'fas fa-eye',
    'edit' => 'fas fa-edit',
    'trash' => 'fas fa-trash',
    'undo' => 'fas fa-undo',
    'check' => 'fas fa-check',
    'times' => 'fas fa-times',
    'exclamation-triangle' => 'fas fa-exclamation-triangle',
    'exclamation-circle' => 'fas fa-exclamation-circle',
    'info-circle' => 'fas fa-info-circle',
    'user' => 'fas fa-user',
    'database' => 'fas fa-database',
    'plus' => 'fas fa-plus',
    'minus' => 'fas fa-minus',
];

$iconClass = $fallback;
if (!$iconClass && isset($fontAwesomeMap[$name])) {
    $iconClass = $fontAwesomeMap[$name];
}

if ($iconClass) {
    $attrString = '';
    foreach ($attributes as $key => $value) {
        $attrString .= ' ' . h($key) . '="' . h($value) . '"';
    }
    echo '<i class="' . h($iconClass) . ' me-2"' . $attrString . '></i>';
} else {
    // Last resort: text label
    echo '[' . h($name) . ']';
}
