<?php

declare(strict_types=1);

namespace Bouncer\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * BouncerRecordsFixture
 */
class BouncerRecordsFixture extends TestFixture
{
    /**
     * Init method
     *
     * @return void
     */
    public function init(): void
    {
        $this->records = [];
        parent::init();
    }
}
