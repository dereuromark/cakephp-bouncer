<?php

declare(strict_types=1);

use Cake\Core\Configure;
use Migrations\BaseMigration;

class AllowGuestBouncerRecords extends BaseMigration
{
    public function up(): void
    {
        $this->table('bouncer_records')
            ->changeColumn('user_id', 'integer', ['null' => true, 'default' => null, 'limit' => 10, 'signed' => !(bool)Configure::read('Migrations.unsigned_primary_keys', false), 'comment' => 'User who proposed the change, null for guest proposals'])
            ->addColumn('contact', 'string', ['limit' => 255, 'null' => true, 'default' => null, 'after' => 'user_display'])
            ->update();
    }

    public function down(): void
    {
        $this->execute('UPDATE bouncer_records SET user_id = 0 WHERE user_id IS NULL');
        $this->table('bouncer_records')
            ->removeColumn('contact')
            ->changeColumn('user_id', 'integer', ['null' => false, 'limit' => 10, 'signed' => !(bool)Configure::read('Migrations.unsigned_primary_keys', false), 'comment' => 'User who proposed the change'])
            ->update();
    }
}
