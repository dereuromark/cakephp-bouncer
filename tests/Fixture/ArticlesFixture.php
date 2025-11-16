<?php

declare(strict_types=1);

namespace Bouncer\Test\Fixture;

use Cake\TestSuite\Fixture\TestFixture;

/**
 * ArticlesFixture
 */
class ArticlesFixture extends TestFixture
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

    /**
     * Create table SQL
     *
     * @return string
     */
    public function createSql(): string
    {
        return <<<SQL
CREATE TABLE IF NOT EXISTS articles (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title VARCHAR(255) NOT NULL,
    body TEXT,
    user_id INTEGER NOT NULL,
    created DATETIME,
    modified DATETIME
);
SQL;
    }

    /**
     * Drop table SQL
     *
     * @return string
     */
    public function dropSql(): string
    {
        return 'DROP TABLE IF EXISTS articles;';
    }
}
