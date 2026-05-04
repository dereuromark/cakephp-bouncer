# Advanced Patterns

Recipes for things beyond "save through a draft, approve in the admin
UI."

## Bypass callbacks

`exemptUsers` is fine for `[1, 2, 3]`-style lists but breaks down for
role-based or entity-based bypass. `bypassCallback` accepts a closure
that runs at every save and can return `true` to bypass approval:

```php
$this->addBehavior('Bouncer.Bouncer', [
    'bypassCallback' => function ($entity, $options, $table) {
        $identity = $options['identity'] ?? null;

        return $identity && $identity->can('bypassBouncer', $entity);
    },
]);
```

The callback receives:

| Param | Type | Meaning |
|---|---|---|
| `$entity` | `EntityInterface` | the entity being saved or deleted |
| `$options` | `ArrayObject` | save/delete options including `bouncerUserId` |
| `$table` | `Table` | the source table |

### Role-based example

```php
$this->addBehavior('Bouncer.Bouncer', [
    'bypassCallback' => function ($entity, $options, $table) {
        $userId = $options['bouncerUserId'] ?? $entity->get('user_id');
        $user   = $table->fetchTable('Users')->get($userId);

        return in_array($user->role, ['admin', 'editor'], true);
    },
]);
```

### Entity-based example

Skip approval for entities that are still drafts in the host app's own
publishing model — admins approve only the *publication step*:

```php
$this->addBehavior('Bouncer.Bouncer', [
    'bypassCallback' => function ($entity, $options, $table) {
        return $entity->status === 'draft';
    },
]);
```

`exemptUsers` still works as a simpler fallback when both are configured.

## Conditional Bouncer attachment

Attach the behavior only for users that need it, leaving privileged users
on the normal save path:

```php
// In AppController::beforeFilter
public function beforeFilter(EventInterface $event)
{
    parent::beforeFilter($event);

    $identity = $this->Authentication->getIdentity();

    if (!$identity?->isAdmin()) {
        $this->Articles->addBehavior('Bouncer.Bouncer', [
            'userField' => 'user_id',
        ]);
    }
}
```

This is cleaner than adding the behavior unconditionally and bypassing —
the table without the behavior never even attempts to serialize a draft,
so there's no overhead and no surprise edge cases.

## Per-save bypass

For one-off privileged saves — admin direct edit, automated import,
content migration:

```php
$this->Articles->save($article, ['bypassBouncer' => true]);
```

Bouncer skips the entire `beforeSave` short-circuit for this call only.
No draft, no `wasBounced()` flag.

## Custom approval flow

For when the bundled admin UI doesn't fit — a separate moderator app,
auto-approval pipelines, batch operations:

```php
use Cake\I18n\DateTime;

public function customApprove($id)
{
    $bouncerTable  = $this->fetchTable('Bouncer.BouncerRecords');
    $bouncerRecord = $bouncerTable->get($id);

    // Custom validation: business rules beyond the source table's
    // validators (e.g., "comment proposals can't include URLs").
    if (!$this->validateProposal($bouncerRecord)) {
        $this->Flash->error('Proposal does not meet requirements');

        return $this->redirect(['action' => 'view', $id]);
    }

    $sourceTable = $this->fetchTable($bouncerRecord->source);
    $sourceTable->addBehavior('Bouncer.Bouncer');

    $connection = $bouncerTable->getConnection();
    $connection->transactional(function () use ($bouncerRecord, $sourceTable, $bouncerTable) {
        $entity = $sourceTable
            ->getBehavior('Bouncer')
            ->applyApprovedChanges($bouncerRecord);

        $bouncerTable->patchEntity($bouncerRecord, [
            'status'      => 'approved',
            'reviewer_id' => $this->Authentication->getIdentity()->getIdentifier(),
            'reviewed'    => new DateTime(),
            'primary_key' => $entity->id,
        ]);
        $bouncerTable->save($bouncerRecord);
    });

    $this->Flash->success('Approved and published');

    return $this->redirect(['action' => 'index']);
}
```

The transactional wrapper is important — without it, a partial failure
between "apply changes" and "mark bouncer_record approved" leaves the
queue in a confusing state ("the change is live but the record still
shows as pending").

## Status-change notifications

Wire notifications by listening on the `BouncerRecords` table's
`afterSave` event:

```php
// src/Model/Table/BouncerRecordsTable.php (or via an event listener)
public function afterSave(EventInterface $event, EntityInterface $entity, ArrayObject $options)
{
    if (!$entity->isDirty('status')) {
        return;
    }

    if ($entity->status === 'approved') {
        $this->notifyUser(
            $entity->user_id,
            __('Your changes were approved!'),
        );
    } elseif ($entity->status === 'rejected') {
        $this->notifyUser(
            $entity->user_id,
            __('Your changes were rejected: {0}', $entity->reason),
        );
    }
}

protected function notifyUser($userId, string $message): void
{
    // Send email, create in-app notification, push to Slack — whatever
    // your stack does.
}
```

For pending → admin notifications (the inverse — "tell admins someone
proposed something"), do the same in the new-record branch
(`$entity->isNew()` and status === 'pending'`).

## Dashboard widget

Surface the pending count on your existing admin dashboard:

```php
public function dashboard()
{
    $bouncerTable = $this->fetchTable('Bouncer.BouncerRecords');

    // findPending() is the canonical finder — equivalent to
    // ->find()->where(['status' => 'pending']) but reads cleaner.
    $pendingCount = $bouncerTable->findPending()->count();

    $this->set(compact('pendingCount'));
}
```

Render it as a badge in the dashboard tile linking to
`/admin/bouncer/bouncer`. Bouncer's table is single-row-per-proposal, so
the unfiltered `count()` is cheap.

For per-record checks (e.g., a "you have changes pending on this article"
banner on the public view), use `findPendingForRecord` — it scopes by
source / primary key and optionally by user:

```php
$pending = $bouncerTable
    ->findPendingForRecord('Articles', $articleId, $userId)
    ->first();

if ($pending) {
    // surface "your draft is in review" UI
}
```

`$userId` is optional — omit it to find any pending proposal for that
record, regardless of who proposed it.
