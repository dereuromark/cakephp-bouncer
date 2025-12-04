<?php

declare(strict_types=1);

namespace TestApp\View;

use Cake\View\View;

class AppView extends View
{
    /**
     * @inheritDoc
     */
    public function initialize(): void
    {
        parent::initialize();

        $this->loadHelper('Html');
        $this->loadHelper('Form');
        $this->loadHelper('Flash');
        $this->loadHelper('Number');
    }
}
