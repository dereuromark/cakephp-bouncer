# Troubleshooting

## Changes save directly instead of being bounced

You expected a draft but the save hit the source table.

**Check, in order:**

1. **Behavior attached** — `$this->Articles->hasBehavior('Bouncer')`
   returns `true` at save time. If you attach it conditionally
   (e.g. only for non-admins), make sure the user actually went through
   the conditional branch.
2. **Action in `requireApproval`** — defaults to `['add', 'edit', 'delete']`.
   If you set `['edit']`, new records pass through.
3. **User ID is reaching the behavior** — pass via `bouncerUserId` in
   save options, or have a `user_id` field on the entity. Without a nonempty user ID,
   draft creation fails and the source save is blocked.
4. **User isn't in `exemptUsers`** — check the configured list and the
   user's id type (string vs int).
5. **`bypassCallback` returns literal true**: other truthy values do not
   bypass. Check the permission decision made by the callback.
6. **`bypassBouncer` save option not set** — `save($entity, ['bypassBouncer' => true])`
   bypasses for that one call.

## Drafts not loading on re-edit

User edits the same record but doesn't see their pending draft — they
end up overwriting their own proposal.

**Fix:** call `loadDraft($primaryKey, $userId)` in the controller before
rendering the form, and overlay the draft data on the published entity
(see the [Getting Started](../guide/) edit example).

`loadDraft` returns `null` for any of: no pending draft exists; the draft
belongs to a different user; the draft is in a non-pending state. The
common cause is a user ID mismatch — verify the `bouncerUserId` you pass
is the same one stored on the bouncer record.

## Validation errors when creating a draft

The user submits valid-looking data but the draft creation fails with
validation errors.

**Three options:**

1. **Fix the validation rules** — `validateOnDraft => true` (default)
   runs the table's normal validation. If the rules are too strict for
   draft proposals (e.g., "must reference an approved category" when
   categories are themselves bouncer-managed), loosen them.
2. **Defer validation to approval** — `validateOnDraft => false` accepts
   any payload as a draft. Validation only runs when the admin approves.
3. **Add a draft-specific validator** — split your validation into
   `validationDefault()` and `validationDraft()` and have the bouncer
   behavior pick the draft variant.

## Approve button does nothing visible

The admin clicks approve and… nothing changes. No flash message, or a
generic failure message.

**Investigate:**

1. **Logs first** — `applyApprovedChanges()` is transactional. If it
   threw, the exception is in `logs/error.log` even when the UI is
   silent.
2. **Source table still exists** — if the proposal is for a record that's
   since been deleted, the apply will fail. Check
   `bouncerRecord->primary_key` against the source.
3. **Source table validation** — even if `validateOnDraft` is true, the
   source table's *save-time* rules run again on apply. A unique-key
   collision that didn't exist when the draft was made will now reject.
4. **Database constraints** — foreign key / unique violations bubble up
   as exceptions. The admin UI catches them and re-renders with an
   error; the `bouncer_record` stays in `pending`.
5. **Stale proposal with conflicts** — if [3-way merge](./three-way-merge)
   finds conflicts, the bundled admin approve action redirects to resolution.
   Direct `applyApprovedChanges()` calls use the proposed value on conflicts;
   check merge results and set resolved data before applying when needed.

## Multiple pending drafts for the same record

The queue shows two or three pending drafts for one source row from the
same user.

**With `autoSupersede => true` (default):** this shouldn't happen for
the same `(source, primary_key, user_id)`. Check the actual source aliases, primary keys, user IDs, and concurrent requests.
Indexes alone do not enforce a unique pending draft.

**With `autoSupersede => false`:** proposals from different users stay pending.
Re-editing a same-user draft still updates the existing proposal. Switching between edit and delete proposals can also leave both pending for
the same user. Other duplicates require checking the identifiers and concurrent
requests above.
To supersede other proposals manually:

```php
$bouncerTable->supersedeOthers($source, $primaryKey, $keepId);
```

`$keepId` is the proposal id you want to leave in `pending`; the rest get
flipped to `superseded`.

## Best practices

A short checklist that prevents most of the issues above:

1. **Always load drafts in edit actions** — `loadDraft()` first, then
   render. Prevents users overwriting their own proposals.
2. **Distinguish bounced from saved in your UI** — different flash
   message, different redirect. `wasBounced()` makes this trivial.
3. **Validate on draft (the default)** — catch obvious errors early
   rather than at admin-review time.
4. **Wrap programmatic approvals in transactions** — see
   [Custom approval flow](./advanced-patterns#custom-approval-flow) for
   the pattern.
5. **Wire status notifications** — the proposer doesn't know their
   change was approved/rejected unless something tells them. See
   [Status-change notifications](./advanced-patterns#status-change-notifications).
6. **Pair with AuditStash** — the approval workflow is one half of the
   story; the other half is the actual data changes. See
   [AuditStash Integration](./audit-stash-integration).
7. **Test the bypass paths** — exempt-user lists and bypass callbacks
   are exactly the kind of code that's silently wrong until it isn't.
8. **Document exemptions clearly** — if `bypassCallback` exempts users
   based on role, leave a comment explaining *why* (audit trail of
   intent, not just code).
