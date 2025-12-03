<?php

declare(strict_types=1);

namespace Bouncer\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * UuidArticlesFixture - Articles with UUID primary key and user_id
 */
class UuidArticlesFixture extends TestFixture
{
    /**
     * Table name
     *
     * @var string
     */
    public string $table = 'uuid_articles';

    /**
     * Fields
     *
     * @var array<string, mixed>
     */
    public array $fields = [
        'id' => ['type' => 'uuid', 'null' => false, 'default' => null],
        'title' => ['type' => 'string', 'length' => 255, 'null' => false, 'default' => null],
        'body' => ['type' => 'text', 'null' => true, 'default' => null],
        'user_id' => ['type' => 'uuid', 'null' => false, 'default' => null],
        'created' => ['type' => 'datetime', 'length' => null, 'null' => true, 'default' => null],
        'modified' => ['type' => 'datetime', 'length' => null, 'null' => true, 'default' => null],
        '_constraints' => [
            'primary' => ['type' => 'primary', 'columns' => ['id']],
        ],
    ];

    /**
     * Records
     *
     * @var list<array>
     */
    public array $records = [];

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
