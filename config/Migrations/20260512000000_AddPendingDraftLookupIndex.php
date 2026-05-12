<?php

declare(strict_types=1);

use Migrations\BaseMigration;

/**
 * Adds a composite index covering the per-user pending-draft lookup performed
 * by `BouncerRecordsTable::findPendingForRecord($source, $primaryKey, $userId)`
 * on every save against a Bouncer-enabled table.
 *
 * The original migration shipped `(source, primary_key, status)` which works
 * well for the global lookup but leaves `user_id` as a residual filter; adding
 * it to the composite makes the per-user case index-only. The standalone
 * `(user_id)` index from the original migration is preserved — it still serves
 * the admin-side "show all pending drafts by user X" queries.
 */
class AddPendingDraftLookupIndex extends BaseMigration
{
    public function up(): void
    {
        $this->table('bouncer_records')
            ->addIndex(
                ['source', 'primary_key', 'status', 'user_id'],
                ['name' => 'bouncer_pending_lookup'],
            )
            ->update();
    }

    public function down(): void
    {
        $this->table('bouncer_records')
            ->removeIndexByName('bouncer_pending_lookup')
            ->update();
    }
}
