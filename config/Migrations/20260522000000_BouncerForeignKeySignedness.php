<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Migrations\BaseMigration;

class BouncerForeignKeySignedness extends BaseMigration
{
    /**
     * The `primary_key` (polymorphic record reference), `user_id` and
     * `reviewer_id` columns reference primary keys, so they must use the same
     * signedness as the application's primary keys, governed by the
     * `Migrations.unsigned_primary_keys` flag. The original migration hardcoded
     * them as unsigned, which mismatches signed-primary-key apps.
     * Signedness only takes effect on MySQL; SQLite/Postgres ignore it.
     *
     * @return void
     */
    public function up(): void
    {
        $signed = !(bool)Configure::read('Migrations.unsigned_primary_keys');

        $this->table('bouncer_records')
            ->changeColumn('primary_key', 'integer', [
                'default' => null,
                'limit' => 10,
                'null' => true,
                'signed' => $signed,
                'comment' => 'ID of record in source table, NULL for new records',
            ])
            ->changeColumn('user_id', 'integer', [
                'default' => null,
                'limit' => 10,
                'null' => false,
                'signed' => $signed,
                'comment' => 'User who proposed the change',
            ])
            ->changeColumn('reviewer_id', 'integer', [
                'default' => null,
                'limit' => 10,
                'null' => true,
                'signed' => $signed,
                'comment' => 'Admin who approved/rejected',
            ])
            ->update();
    }

    /**
     * @return void
     */
    public function down(): void
    {
        $this->table('bouncer_records')
            ->changeColumn('primary_key', 'integer', [
                'default' => null,
                'limit' => 10,
                'null' => true,
                'signed' => false,
                'comment' => 'ID of record in source table, NULL for new records',
            ])
            ->changeColumn('user_id', 'integer', [
                'default' => null,
                'limit' => 10,
                'null' => false,
                'signed' => false,
                'comment' => 'User who proposed the change',
            ])
            ->changeColumn('reviewer_id', 'integer', [
                'default' => null,
                'limit' => 10,
                'null' => true,
                'signed' => false,
                'comment' => 'Admin who approved/rejected',
            ])
            ->update();
    }
}
