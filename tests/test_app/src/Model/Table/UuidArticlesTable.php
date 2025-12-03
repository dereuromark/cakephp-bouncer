<?php

declare(strict_types=1);

namespace TestApp\Model\Table;

use Cake\ORM\Table;

/**
 * UuidArticles Table - uses UUID for primary key and user_id
 */
class UuidArticlesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('uuid_articles');
        $this->setDisplayField('title');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');
    }
}
