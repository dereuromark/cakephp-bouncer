# Getting Started

`cakephp-bouncer` adds an approval workflow to any CakePHP Table: instead
of saving directly, user proposals are stored as drafts in a
`bouncer_records` table; an admin or moderator reviews them with diff
output and approves, rejects, or supersedes.

The original record stays untouched until an admin approves the proposal.

## Requirements

- PHP 8.2+
- CakePHP 5.1+

See the [version map](https://github.com/dereuromark/cakephp-bouncer/wiki#cakephp-version-map)
for older Cake/PHP combinations.

## Installation

```bash
composer require dereuromark/cakephp-bouncer
bin/cake plugin load Bouncer
bin/cake migrations migrate -p Bouncer
```

The migration creates the `bouncer_records` table.

## Enable on a Table

Add the behavior to any table whose changes should require approval:

```php
class ArticlesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->addBehavior('Bouncer.Bouncer', [
            'userField' => 'user_id', // identifies the proposer
        ]);
    }
}
```

That alone activates approval for `add` and `edit`. The plugin intercepts
`beforeSave` / `beforeDelete`, serializes the proposed entity into a
`bouncer_record`, and short-circuits the actual write.

## Wire up the controller

The controller needs to do two extra things vs. a plain CRUD controller:

1. Pass the proposing user via the `bouncerUserId` save option (so the
   draft remembers who proposed it).
2. On edit, load any existing pending draft for that user/record, so the
   user keeps editing their own proposal instead of stacking duplicates.

```php
public function edit($id = null)
{
    $article = $this->Articles->get($id);
    $userId  = $this->Authentication->getIdentity()->getIdentifier();

    $draft = $this->Articles->getBehavior('Bouncer')->loadDraft($id, $userId);
    if ($draft) {
        $article = $this->Articles->patchEntity($article, $draft->getData());
        $this->Flash->info('You are editing your pending draft');
    }

    if ($this->request->is(['patch', 'post', 'put'])) {
        $article = $this->Articles->patchEntity($article, $this->request->getData());
        $this->Articles->save($article, ['bouncerUserId' => $userId]);

        if ($this->Articles->getBehavior('Bouncer')->wasBounced()) {
            $this->Flash->success('Your changes are pending approval');

            return $this->redirect(['action' => 'index']);
        }
    }

    $this->set(compact('article'));
}
```

`wasBounced()` returns `true` when the save was diverted into a draft —
use it to flash a different message and skip the "saved successfully"
redirect.

## Connect the admin routes

In `config/routes.php`:

```php
$routes->prefix('Admin', function (RouteBuilder $routes) {
    $routes->plugin('Bouncer', function (RouteBuilder $routes) {
        $routes->connect('/pending', ['controller' => 'Bouncer', 'action' => 'index']);
        $routes->fallbacks();
    });
});
```

Navigate to `/admin/bouncer/bouncer` to see the queue, side-by-side diffs,
and approve / reject buttons.

## Where next

- [Configuration](./configuration) — behavior options and the `Bouncer.*`
  app config keys
- [Usage](./usage) — controller patterns including draft re-edit, bypass,
  and per-save options
- [View Helper](./view-helper) — render proposals in your own templates
- [Features](/features/) — admin UI, approval workflow internals, 3-way
  merge, advanced patterns, AuditStash integration
