# Approval Workflow

The flow Bouncer takes a save through, the database row that captures it,
and the helpers on the entity that drive the UI.

## How a save becomes a draft

```
user calls $table->save($entity, ['bouncerUserId' => $userId])
        │
        ▼
BouncerBehavior::beforeSave  (or beforeDelete for deletes)
        │
        ├── bypassBouncer option set? ─→ pass through, normal save
        ├── exemptUsers / bypassCallback matches? ─→ pass through
        ├── action not in requireApproval? ─→ pass through
        │
        ▼
serialize entity to JSON, write a bouncer_record row (status='pending')
        │
        ▼
short-circuit the original save (return false from beforeSave)
        │
        ▼
behavior remembers wasBounced() = true for the controller to read
```

On approval the flow runs in reverse: the bouncer_record's `data` payload
is patched back onto a fresh entity from the source table, validated
again (because draft validation isn't a substitute for save-time rules),
and saved with bouncer **bypassed** for that specific call so it doesn't
re-loop.

## Re-edit deduplication

When the same user edits the same record again before the previous
proposal was reviewed, Bouncer doesn't stack drafts:

1. The controller calls `loadDraft($primaryKey, $userId)` and gets the
   existing pending row.
2. The form is rendered with the draft's data overlaid on the published
   record.
3. On submit, `BouncerBehavior::beforeSave` finds the existing pending
   draft for `(source, primary_key, user_id)` and **updates** it rather
   than inserting a new one.

Different users editing the same record *do* get separate drafts —
otherwise concurrent contributors would silently overwrite each other.
With `autoSupersede => true` (default), submitting a *new* proposal for
the same record marks the user's previous pending drafts as `superseded`
so the queue stays focused on the latest version.

## Database schema

The `bouncer_records` table stores one row per proposal:

| Column | Type | Purpose |
|---|---|---|
| `id` | integer / uuid | primary key |
| `source` | string | model name (`Articles`, `Community.Stories` for plugins) |
| `primary_key` | integer / uuid / null | source-record id, NULL for new-record proposals |
| `user_id` | integer / uuid | who proposed |
| `user_display` | string / null | optional display name for the proposer |
| `reviewer_id` | integer / uuid / null | who approved or rejected |
| `reviewer_display` | string / null | optional display name for the reviewer |
| `status` | enum | `pending` / `approved` / `rejected` / `superseded` |
| `data` | JSON | proposed changes (full entity payload for new records, dirty fields for edits) |
| `original_data` | JSON / null | snapshot of the source record at draft time — drives diffs and 3-way merge |
| `original_modified` | datetime / null | source record's `modified` timestamp at draft time — drives staleness detection |
| `reason` | text / null | reviewer's note when rejecting (or approving with comment) |
| `created` / `modified` / `reviewed` | datetime | the usual lifecycle timestamps |

`primary_key` is `null` for proposals that would create a new row.
`original_data` is `null` for the same reason.

## Status transitions

```
                ┌─────────┐
                │ pending │ ← every new proposal starts here
                └────┬────┘
            ┌───────┼───────┐
            ▼       ▼       ▼
    ┌────────────┐ ┌──────────┐ ┌────────────┐
    │  approved  │ │ rejected │ │ superseded │
    └────────────┘ └──────────┘ └────────────┘
```

Statuses are terminal — once a row leaves `pending`, the queue stops
showing it (unless the admin filters for that status).

## Entity helpers on `BouncerRecord`

The entity exposes a few convenience methods used by the admin UI and
useful in custom code:

```php
// Has the source record changed since this draft was made?
$bouncerRecord->isStale($currentSourceEntity);

// What does a 3-way merge look like? Returns null if not stale.
$result = $bouncerRecord->buildMergeResult($currentSourceEntity);
// $result = ['merged' => [...], 'conflicts' => [...], 'autoMerged' => bool, 'hasConflicts' => bool]

// Override the apply payload with your own merged data (UI-driven
// conflict resolution, custom logic, etc.)
$bouncerRecord->setMergedData(['title' => 'Hand-merged title', /* ... */]);

// Read the proposed payload (whatever's been set, including merged data)
$data = $bouncerRecord->getData();
```

## UUID support

Bundled migrations use integer columns. UUID-keyed apps need to copy and
adjust them — see
[Configuration → UUID Primary Keys](../guide/configuration#uuid-primary-keys).
