# Getting started

Follow [Installation](installation.md) first. This example adds proposal handling
to an existing `Articles` table and edit form. It assumes your application already
provides an authenticated identity and normal CRUD routing and templates.

## Enable the behavior

Add the behavior in your existing `src/Model/Table/ArticlesTable.php` initializer:

```php
public function initialize(array $config): void
{
    parent::initialize($config);
    $this->addBehavior('Bouncer.Bouncer');
}
```

Keep existing table initialization and associations. By default, adds, edits, and
deletes require approval. Saves that are diverted into proposals return false;
use the behavior's result flags to distinguish a proposal from a failed save.
Bulk SQL writes such as `updateAll()` and `deleteAll()` bypass this behavior.

## Submit an edit

In your existing `ArticlesController`, adapt the edit action:

```php
public function edit($id = null)
{
    $identity = $this->getRequest()->getAttribute('identity');
    if ($identity === null) {
        throw new \Cake\Http\Exception\ForbiddenException('Sign in to propose changes.');
    }
    $userId = $identity->getIdentifier();
    $articles = $this->fetchTable('Articles');
    $behavior = $articles->getBehavior('Bouncer');
    $article = $articles->get($id);
    $behavior->withDraft($article, $userId);

    if ($this->request->is(['patch', 'post', 'put'])) {
        $article = $articles->patchEntity($article, $this->request->getData());
        $saved = $articles->save($article, ['bouncerUserId' => $userId]);

        if ($behavior->wasBounced()) {
            $this->Flash->success('Your changes are pending approval.');

            return $this->redirect(['action' => 'index']);
        }
        if ($behavior->wasDraftRemoved()) {
            $this->Flash->info('Your pending draft was cancelled.');

            return $this->redirect(['action' => 'index']);
        }
        if ($saved) {
            $this->Flash->success('Article saved.');

            return $this->redirect(['action' => 'index']);
        }
        $this->Flash->error('The proposal could not be saved.');
    }

    $this->set(compact('article'));
}
```

`withDraft()` overlays this user's pending proposal on the published entity.
The save option identifies the proposer without requiring a `user_id` column on
Articles. Keep your existing authorization, CSRF, and FormProtection handling.

## Review the proposal

Protect the admin UI using your host application's access checks or a
`Bouncer.accessCheck` Closure. See [Admin UI](../features/admin-ui.md#access-control)
for the access options. Then open `/admin/bouncer` to review the queued proposal.
Loading the plugin already registers these routes; no duplicate route block is
needed.

The published article stays unchanged until approval. The UI shows the proposal
and current data before applying it. Review stale proposals carefully: automatic
three-way merging can keep the proposed value on conflicts. See
[Three-way merge](../features/three-way-merge.md) for the actual resolution rules.

## Next steps

- [Configuration](configuration.md): behavior options and application settings.
- [Usage](usage.md): adding records, notes, bypasses, and programmatic approval.
- [Admin UI](../features/admin-ui.md): routes, review actions, and access control.
- [Troubleshooting](../features/troubleshooting.md): failed proposals and transactions.
