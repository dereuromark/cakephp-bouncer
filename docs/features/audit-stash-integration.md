# AuditStash Integration

Bouncer and [cakephp-audit-stash](https://github.com/dereuromark/cakephp-audit-stash)
have non-overlapping responsibilities and are designed to work together.

| Plugin | Tracks |
|---|---|
| **Bouncer** | the *approval workflow* — who proposed, who reviewed, when, with what reason |
| **AuditStash** | the *data changes* — every create / update / delete on the source tables, including the ones that approvals apply |

Together you get the full story: not just "this article was changed at
2:14 PM" (audit-stash) but also "the change was originally proposed by
user 42 at 9 AM, sat in queue for five hours, and was approved by user 7
with note 'looks good, ship it'" (bouncer).

## Wiring

### On `BouncerRecordsTable` — track the approval workflow

Make every change to the proposals table itself audited:

```php
// src/Model/Table/BouncerRecordsTable.php (or extend the plugin's)
public function initialize(array $config): void
{
    parent::initialize($config);
    $this->addBehavior('Timestamp');
    $this->addBehavior('AuditStash.AuditLog');
}
```

Now every status flip — pending → approved, pending → rejected, pending →
superseded — produces an audit row capturing who flipped it (the
`reviewer_id`) and any reason note.

### On your application tables — track the data changes

```php
// src/Model/Table/ArticlesTable.php
public function initialize(array $config): void
{
    parent::initialize($config);
    $this->addBehavior('Bouncer.Bouncer');
    $this->addBehavior('AuditStash.AuditLog'); // logs the actual changes
}
```

When an admin approves a proposal, AuditStash logs the resulting save on
the source table — same as it would for a non-bouncer save. The audit row
on `Articles` can be cross-referenced to the bouncer audit row on
`BouncerRecords` via metadata if you want a clickable link in the
viewer.

## Two audit trails

The combined setup gives you:

1. **Bouncer approval workflow audit** — `BouncerRecords` rows + their
   AuditStash audit rows. Answers "who proposed, who reviewed, with what
   note."
2. **Source-data audit** — `Articles` rows + their AuditStash audit rows.
   Answers "what fields changed, before and after values, when."

Both feed the same admin viewer at `/admin/audit-stash/...` if you've
also set that up — see the
[AuditStash docs](https://dereuromark.github.io/cakephp-audit-stash/features/viewer)
for the viewer surface.

## Reverts vs. approvals

Bouncer **does not implement revert**. Approvals go forward in time. To
revert an approved change to a previous state, use AuditStash's
[Revert / Restore feature](https://dereuromark.github.io/cakephp-audit-stash/features/revert) —
it can drive the source table back to any historical state captured in
its audit trail.

That separation is intentional: bouncer is "should this go in?" and
audit-stash is "what did go in, and how do we put it back?"
