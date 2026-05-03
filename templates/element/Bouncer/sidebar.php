<?php
/**
 * Bouncer Admin Sidebar Navigation
 *
 * @var \Cake\View\View $this
 */

$controller = $this->getRequest()->getParam('controller');
$action = $this->getRequest()->getParam('action');
$plugin = $this->getRequest()->getParam('plugin');
$prefix = $this->getRequest()->getParam('prefix');

$isActive = function (string $c, ?array $actions = null) use ($controller, $action): string {
    if ($controller !== $c) {
        return '';
    }
    if ($actions === null) {
        return 'active';
    }

    return in_array($action, $actions, true) ? 'active' : '';
};
?>
<aside class="bouncer-sidebar d-none d-lg-block">
    <!-- Navigation -->
    <div class="nav-section">
        <div class="nav-section-title"><?= __('Navigation') ?></div>
        <nav class="nav flex-column">
            <a class="nav-link <?= $isActive('Bouncer', ['index']) ?>"
               href="<?= $this->Url->build(['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'Bouncer', 'action' => 'index']) ?>">
                <?= $this->element('Bouncer.icon', ['name' => 'list', 'fallback' => 'fas fa-list']) ?>
                <?= __('All Records') ?>
            </a>
        </nav>
    </div>

    <!-- Quick Filters -->
    <div class="nav-section">
        <div class="nav-section-title"><?= __('Status Filters') ?></div>
        <nav class="nav flex-column">
            <a class="nav-link"
               href="<?= $this->Url->build(['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'Bouncer', 'action' => 'index', '?' => ['status' => 'pending']]) ?>">
                <?= $this->element('Bouncer.icon', ['name' => 'clock', 'fallback' => 'fas fa-clock']) ?>
                <?= __('Pending') ?>
            </a>
            <a class="nav-link"
               href="<?= $this->Url->build(['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'Bouncer', 'action' => 'index', '?' => ['status' => 'approved']]) ?>">
                <?= $this->element('Bouncer.icon', ['name' => 'check-circle', 'fallback' => 'fas fa-check-circle']) ?>
                <?= __('Approved') ?>
            </a>
            <a class="nav-link"
               href="<?= $this->Url->build(['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'Bouncer', 'action' => 'index', '?' => ['status' => 'rejected']]) ?>">
                <?= $this->element('Bouncer.icon', ['name' => 'times-circle', 'fallback' => 'fas fa-times-circle']) ?>
                <?= __('Rejected') ?>
            </a>
            <a class="nav-link"
               href="<?= $this->Url->build(['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'Bouncer', 'action' => 'index', '?' => ['status' => 'all']]) ?>">
                <?= $this->element('Bouncer.icon', ['name' => 'globe', 'fallback' => 'fas fa-globe']) ?>
                <?= __('All Statuses') ?>
            </a>
        </nav>
    </div>

    <?php if (\Cake\Core\Configure::read('debug')) { ?>
    <div class="nav-section">
        <div class="nav-section-title text-warning"><?= __d('bouncer', 'Debug') ?></div>
        <nav class="nav flex-column">
            <?= $this->Form->postLink(
                $this->element('Bouncer.icon', ['name' => 'eraser', 'fallback' => 'fas fa-eraser']) . ' ' . __d('bouncer', 'Reset (truncate)'),
                ['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'Bouncer', 'action' => 'reset'],
                [
                    'class' => 'nav-link text-danger',
                    'escapeTitle' => false,
                    'confirm' => __d('bouncer', 'This wipes ALL bouncer records. Continue?'),
                    'block' => true,
                ],
            ) ?>
        </nav>
    </div>
    <?php } ?>
</aside>
