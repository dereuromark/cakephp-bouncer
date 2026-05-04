<?php
/**
 * Bouncer Admin Mobile Navigation
 *
 * Offcanvas navigation for mobile devices.
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
<div class="offcanvas offcanvas-start bouncer-mobile-nav-bg" tabindex="-1" id="bouncerMobileNav" aria-labelledby="bouncerMobileNavLabel">
    <div class="offcanvas-header border-bottom border-secondary">
        <h5 class="offcanvas-title text-white" id="bouncerMobileNavLabel">
            <i class="fas fa-shield-alt me-2"></i>
            Bouncer
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas" aria-label="Close"></button>
    </div>
    <div class="offcanvas-body">
        <!-- Navigation -->
        <div class="mb-4">
            <div class="text-white-50 small text-uppercase mb-2"><?= __d('bouncer', 'Navigation') ?></div>
            <nav class="nav flex-column">
                <a class="nav-link text-white-50 <?= $isActive('Bouncer', ['index']) ? 'text-white fw-bold' : '' ?>"
                   href="<?= $this->Url->build(['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'Bouncer', 'action' => 'index']) ?>">
                    <i class="fas fa-list me-2"></i>
                    <?= __d('bouncer', 'All Records') ?>
                </a>
            </nav>
        </div>

        <!-- Status Filters -->
        <div class="mb-4">
            <div class="text-white-50 small text-uppercase mb-2"><?= __d('bouncer', 'Status Filters') ?></div>
            <nav class="nav flex-column">
                <a class="nav-link text-white-50"
                   href="<?= $this->Url->build(['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'Bouncer', 'action' => 'index', '?' => ['status' => 'pending']]) ?>">
                    <i class="fas fa-clock me-2"></i>
                    <?= __d('bouncer', 'Pending') ?>
                </a>
                <a class="nav-link text-white-50"
                   href="<?= $this->Url->build(['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'Bouncer', 'action' => 'index', '?' => ['status' => 'approved']]) ?>">
                    <i class="fas fa-check-circle me-2"></i>
                    <?= __d('bouncer', 'Approved') ?>
                </a>
                <a class="nav-link text-white-50"
                   href="<?= $this->Url->build(['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'Bouncer', 'action' => 'index', '?' => ['status' => 'rejected']]) ?>">
                    <i class="fas fa-times-circle me-2"></i>
                    <?= __d('bouncer', 'Rejected') ?>
                </a>
                <a class="nav-link text-white-50"
                   href="<?= $this->Url->build(['plugin' => $plugin, 'prefix' => $prefix, 'controller' => 'Bouncer', 'action' => 'index', '?' => ['status' => 'all']]) ?>">
                    <i class="fas fa-globe me-2"></i>
                    <?= __d('bouncer', 'All Statuses') ?>
                </a>
            </nav>
        </div>
    </div>
</div>
