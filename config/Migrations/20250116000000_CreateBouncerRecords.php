<?php

declare(strict_types=1);

use Migrations\BaseMigration;

class CreateBouncerRecords extends BaseMigration
{
    public bool $autoId = false;

    /**
     * Up
     */
    public function up(): void
    {
        $this->table('bouncer_records')
            ->addColumn('id', 'integer', [
                'autoIncrement' => true,
                'default' => null,
                'limit' => 10,
                'null' => false,
                'signed' => false,
            ])
            ->addPrimaryKey(['id'])
            ->addColumn('source', 'string', [
                'default' => null,
                'limit' => 255,
                'null' => false,
                'comment' => 'Table name (e.g., Articles)',
            ])
            ->addColumn('primary_key', 'integer', [
                'default' => null,
                'limit' => 10,
                'null' => true,
                'signed' => false,
                'comment' => 'ID of record in source table, NULL for new records',
            ])
            ->addColumn('user_id', 'integer', [
                'default' => null,
                'limit' => 10,
                'null' => false,
                'signed' => false,
                'comment' => 'User who proposed the change',
            ])
            ->addColumn('reviewer_id', 'integer', [
                'default' => null,
                'limit' => 10,
                'null' => true,
                'signed' => false,
                'comment' => 'Admin who approved/rejected',
            ])
            ->addColumn('status', 'string', [
                'default' => 'pending',
                'limit' => 20,
                'null' => false,
                'comment' => 'pending, approved, rejected, superseded',
            ])
            ->addColumn('data', 'text', [
                'default' => null,
                'limit' => 16777215,
                'null' => false,
                'comment' => 'JSON serialized entity data',
            ])
            ->addColumn('original_data', 'text', [
                'default' => null,
                'limit' => 16777215,
                'null' => true,
                'comment' => 'JSON serialized original data for edits',
            ])
            ->addColumn('reason', 'text', [
                'default' => null,
                'null' => true,
                'comment' => 'Approval/rejection reason',
            ])
            ->addColumn('reviewed', 'datetime', [
                'default' => null,
                'null' => true,
                'comment' => 'When approved/rejected',
            ])
            ->addColumn('created', 'datetime', [
                'default' => null,
                'null' => true,
            ])
            ->addColumn('modified', 'datetime', [
                'default' => null,
                'null' => true,
            ])
            ->addIndex(['source'])
            ->addIndex(['primary_key'])
            ->addIndex(['user_id'])
            ->addIndex(['reviewer_id'])
            ->addIndex(['status'])
            ->addIndex(['created'])
            ->addIndex(['source', 'primary_key', 'status'])
            ->create();
    }

    /**
     * Down
     */
    public function down(): void
    {
        $this->table('bouncer_records')->drop()->save();
    }
}
