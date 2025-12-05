<?php

declare(strict_types=1);

use Migrations\BaseMigration;

class AddOriginalModified extends BaseMigration
{
    /**
     * Up
     */
    public function up(): void
    {
        $this->table('bouncer_records')
            ->addColumn('original_modified', 'datetime', [
                'default' => null,
                'null' => true,
                'after' => 'original_data',
                'comment' => 'Timestamp of source record when draft was created',
            ])
            ->update();
    }

    /**
     * Down
     */
    public function down(): void
    {
        $this->table('bouncer_records')
            ->removeColumn('original_modified')
            ->update();
    }
}
