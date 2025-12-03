<?php

declare(strict_types=1);

use Migrations\BaseMigration;

class AddNoteToBouncerRecords extends BaseMigration
{
    /**
     * Up
     */
    public function up(): void
    {
        $this->table('bouncer_records')
            ->addColumn('note', 'string', [
                'default' => null,
                'limit' => 255,
                'null' => true,
                'after' => 'original_data',
                'comment' => 'User note explaining the reason for the change',
            ])
            ->update();
    }

    /**
     * Down
     */
    public function down(): void
    {
        $this->table('bouncer_records')
            ->removeColumn('note')
            ->update();
    }
}
