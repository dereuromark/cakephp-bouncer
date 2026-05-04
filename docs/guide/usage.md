# Usage Patterns

Beyond the [getting-started controller pattern](./), these are the common
shapes you'll need in real apps.

## Standard CRUD with bouncer

### Add

```php
public function add()
{
    $article = $this->Articles->newEmptyEntity();

    if ($this->request->is('post')) {
        $article = $this->Articles->patchEntity($article, $this->request->getData());

        $userId = $this->Authentication->getIdentity()->getIdentifier();
        $this->Articles->save($article, ['bouncerUserId' => $userId]);

        if ($this->Articles->getBehavior('Bouncer')->wasBounced()) {
            $this->Flash->success('Your submission is pending approval');

            return $this->redirect(['action' => 'index']);
        }

        if ($article->hasErrors()) {
            $this->Flash->error('Please correct the errors below');
        }
    }

    $this->set(compact('article'));
}
```

### Edit (with draft overlay via `withDraft`)

```php
public function edit($id = null)
{
    $article = $this->Articles->get($id);
    $userId  = $this->Authentication->getIdentity()->getIdentifier();

    // If the user has a pending draft for this record, overlay it on the
    // published row so they're editing their own proposal, not the live
    // record. withDraft() patches in-place and returns the BouncerRecord
    // (or null if no draft exists).
    $draft = $this->Articles->getBehavior('Bouncer')->withDraft($article, $userId);
    if ($draft) {
        $this->set('draftId', $draft->id);
        $this->Flash->info('You are editing your pending draft');
    }

    if ($this->request->is(['patch', 'post', 'put'])) {
        $article = $this->Articles->patchEntity($article, $this->request->getData());
        $this->Articles->save($article, ['bouncerUserId' => $userId]);

        $behavior = $this->Articles->getBehavior('Bouncer');

        if ($behavior->wasBounced()) {
            $this->Flash->success('Your changes are pending approval');

            return $this->redirect(['action' => 'index']);
        }

        // The user reverted their draft to the original — bouncer
        // removes the now-empty draft from the queue.
        if ($behavior->wasDraftRemoved()) {
            $this->Flash->info('Your pending draft was cancelled');

            return $this->redirect(['action' => 'index']);
        }
    }

    $this->set(compact('article'));
}
```

`withDraft()` is the canonical helper — it does the `loadDraft + patchEntity`
combination in one call. The lookup uses (source, primary key, user ID),
so drafts from *other* users are not surfaced to the current user.

If you need the BouncerRecord object without overlaying onto an existing
entity, `loadDraft($primaryKey, $userId)` returns it directly. After a
save, `getLastBouncerRecord()` returns the row that was just queued —
useful for "show what was submitted" success screens.

### Reading the user from the entity

If your entity already carries a `user_id`, you don't strictly need the
`bouncerUserId` save option:

```php
$this->addBehavior('Bouncer.Bouncer', [
    'userField' => 'user_id',
]);

// Controller:
$article->user_id = $this->Authentication->getIdentity()->getIdentifier();
$this->Articles->save($article); // Bouncer reads user_id from entity
```

The save option still wins when both are present — useful when an admin
saves on behalf of another user.

## Checking for a user's pending draft

Surface a "you have changes pending" badge on the index page:

```php
$hasDraft = $this->Articles
    ->getBehavior('Bouncer')
    ->hasPendingDraft($articleId, $userId);

if ($hasDraft) {
    $this->Flash->info('You have pending changes for this record');
}
```

## Capturing a proposer note

The `bouncer_records` table has a dedicated `note` column for the
**proposer's** explanation ("fixing a typo in §3", "rephrased the intro
for clarity"). It's separate from the `reason` column, which holds the
**reviewer's** approval / rejection note.

Add a textarea to your form and pass the value through save options:

```php
// In your form:
<?= $this->Form->control('bouncer_note', [
    'label' => 'Why are you proposing this change?',
    'type'  => 'textarea',
    'rows'  => 3,
]) ?>

// In your controller:
public function edit($id = null)
{
    $article = $this->Articles->get($id);
    $userId  = $this->Authentication->getIdentity()->getIdentifier();
    $this->Articles->getBehavior('Bouncer')->withDraft($article, $userId);

    if ($this->request->is(['patch', 'post', 'put'])) {
        $data = $this->request->getData();
        $note = $data['bouncer_note'] ?? null;
        unset($data['bouncer_note']); // not part of the entity

        $article = $this->Articles->patchEntity($article, $data);
        $this->Articles->save($article, [
            'bouncerUserId' => $userId,
            'bouncerNote'   => $note,
        ]);

        // ...wasBounced / wasDraftRemoved branches as usual
    }

    $this->set(compact('article'));
}
```

The bundled admin viewer renders the note next to the diff so reviewers
have the proposer's intent at hand when deciding whether to approve.

## Bypassing per save

A trusted action — admin direct edit, automated import, content migration
— can bypass approval entirely:

```php
$this->Articles->save($article, ['bypassBouncer' => true]);
```

The save behaves like the behavior was never attached for this call. No
draft, no `wasBounced()` flag, no admin queue entry.

For bypass driven by *who is saving* rather than which call, see
[Advanced Patterns → bypass callbacks](/features/advanced-patterns).

## Programmatic approval

Approve a draft from your own controller / command, outside the bundled
admin UI:

```php
use Cake\I18n\DateTime;

$bouncerTable  = $this->fetchTable('Bouncer.BouncerRecords');
$bouncerRecord = $bouncerTable->get($id);

$articlesTable = $this->fetchTable('Articles');
$articlesTable->addBehavior('Bouncer.Bouncer');

$entity = $articlesTable
    ->getBehavior('Bouncer')
    ->applyApprovedChanges($bouncerRecord);

if ($entity) {
    $bouncerTable->patchEntity($bouncerRecord, [
        'status'      => 'approved',
        'reviewer_id' => $adminUserId,
        'reviewed'    => new DateTime(),
    ]);
    $bouncerTable->save($bouncerRecord);
}
```

Wrap both saves in a transaction (the admin controller does — see the
[advanced patterns page](/features/advanced-patterns#custom-approval-flow)
for a full transactional version).
