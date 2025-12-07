<?php

declare(strict_types=1);

use Migrations\BaseMigration;

class AddUserDisplayColumns extends BaseMigration
{
    /**
     * Up - Add optional display name columns for user and reviewer
     */
    public function up(): void
    {
        $this->table('bouncer_records')
            ->addColumn('user_display', 'string', [
                'default' => null,
                'limit' => 255,
                'null' => true,
                'after' => 'user_id',
                'comment' => 'Display name for user (optional)',
            ])
            ->addColumn('reviewer_display', 'string', [
                'default' => null,
                'limit' => 255,
                'null' => true,
                'after' => 'reviewer_id',
                'comment' => 'Display name for reviewer (optional)',
            ])
            ->update();
    }

    /**
     * Down - Remove display name columns
     */
    public function down(): void
    {
        $this->table('bouncer_records')
            ->removeColumn('user_display')
            ->removeColumn('reviewer_display')
            ->update();
    }
}
