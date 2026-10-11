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

Draft lookup includes the user ID, but auto-supersede applies to all pending
proposals for the source and primary key. With its default value of true, a new
proposal can supersede another user's draft. Set it to false to retain concurrent
proposals from different users.

## Database schema

The `bouncer_records` table stores one row per proposal:

| Column | Type | Purpose |
|---|---|---|
| `id` | integer | proposal primary key |
| `source` | string | model name (`Articles`, `Community.Stories` for plugins) |
| `primary_key` | integer / uuid / null | source-record id, NULL for new-record proposals |
| `user_id` | integer by default | who proposed |
| `user_display` | string / null | optional display name for the proposer |
| `reviewer_id` | integer / null by default | who approved or rejected |
| `reviewer_display` | string / null | optional display name for the reviewer |
| `status` | string | `pending` / `approved` / `rejected` / `superseded` |
| `data` | JSON | JSON-encoded text: new-record fields, dirty edit fields, or a delete marker |
| `original_data` | JSON / null | snapshot of the source record at draft time — drives diffs and 3-way merge |
| `original_modified` | datetime / null | source record's `modified` timestamp at draft time — drives staleness detection |
| `note` | string / null | the **proposer's** note explaining what they changed and why |
| `reason` | text / null | the **reviewer's** note when approving or rejecting |
| `created` / `modified` / `reviewed` | datetime | the usual lifecycle timestamps |

`primary_key` is `null` for proposals that would create a new row.
`original_data` is `null` for the same reason.

> [!IMPORTANT]
> `note` and `reason` are two different fields with two different
> authors. The bundled admin viewer renders them as **"User Note:"** (the
> proposer's `note`) and **"Reviewer Reason:"** (the reviewer's `reason`).
> Don't conflate them — see [Capturing a proposer note](../guide/usage#capturing-a-proposer-note)
> for how to populate `note` from your forms.

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

Rows that leave pending are hidden from the default queue, but can be listed
using status filters. The admin reopen action returns rejected rows to pending.

## Entity helpers on `BouncerRecord`

The entity exposes predicates and accessors that the admin UI uses
internally and that are useful in your own templates and custom flows.

### Status predicates

| Method | Returns |
|---|---|
| `isPending()` | `true` when `status === 'pending'` |
| `isApproved()` | `true` when `status === 'approved'` |
| `isRejected()` | `true` when `status === 'rejected'` |

### Proposal-type predicates

| Method | Returns |
|---|---|
| `isNewRecordProposal()` | `true` when the proposal would create a row (`primary_key` is null) |
| `isEditProposal()` | `true` when the proposal would modify an existing row |
| `isDeleteProposal()` | `true` when the proposal would delete an existing row |

These drive `BouncerHelper::recordTypeBadge()` — use them when you need
the same branching in your own templates.

### Data accessors

| Method | Returns |
|---|---|
| `getData()` | decoded proposed payload |
| `getOriginalData()` | snapshot of the source record at draft time |
| `hasMergedData()` | `true` if a custom merge result has been set |
| `getMergedData()` | the explicitly-set merged payload, or the proposed payload if none |

### Staleness and merging

| Method | Returns |
|---|---|
| `hasOriginalModified()` | `true` if the row has an `original_modified` timestamp |
| `canDetectStaleness()` | `true` if staleness can be evaluated (proposal is an edit + has `original_modified`) |
| `isStale($currentSourceEntity)` | `true` if the source has moved since the draft was made |
| `buildMergeResult($currentSourceEntity, $skipFields = ['id', 'created', 'modified', '_delete'])` | full 3-way merge result, or `null` if not stale |
| `setMergedData(array $mergedData)` | override the apply payload (for UI-driven conflict resolution) |

```php
// Standard pattern: only render the merge UI when there's something to merge
if ($bouncerRecord->isEditProposal() && $bouncerRecord->canDetectStaleness()) {
    $current = $articles->get($bouncerRecord->primary_key);

    if ($bouncerRecord->isStale($current)) {
        $result = $bouncerRecord->buildMergeResult($current);
        if ($result['hasConflicts']) {
            // surface conflict UI; resolve into $mergedData; then:
            $bouncerRecord->setMergedData($mergedData);
        }
    }
}
```

## UUID support

The source record key uses `Polymorphic.type` at migration time. Proposal IDs
remain integers, and user/reviewer IDs default to integer columns. Configure
source key types before installation and use application migrations for existing
schema changes. See
[Configuration → Polymorphic Column Type](../guide/configuration#polymorphic-column-type).
