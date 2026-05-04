# Configuration

Bouncer has two configuration surfaces: **behavior options** (per-table) and
**app config keys** under `Bouncer.*` (plugin-wide).

## Behavior options

```php
$this->addBehavior('Bouncer.Bouncer', [
    // Field that contains the user ID — required for tracking who proposed.
    // Bouncer reads this from the entity if present; the controller can
    // override per-save via the 'bouncerUserId' option.
    'userField' => 'user_id',

    // Which actions go through approval. Default: ['add', 'edit', 'delete'].
    'requireApproval' => ['add', 'edit', 'delete'],

    // User IDs that bypass bouncer entirely. Use a callback for anything
    // beyond "this list of admin IDs" — see Advanced Patterns.
    'exemptUsers' => [1, 2, 3],

    // More flexible bypass than exemptUsers — closure receives entity,
    // options ArrayObject, and the table. Return true to bypass.
    'bypassCallback' => null,

    // Validate entity data when creating the draft (default: true).
    // Set false to defer validation until approval.
    'validateOnDraft' => true,

    // When the same user submits a new proposal for the same record, mark
    // their previous pending drafts as 'superseded' (default: true).
    'autoSupersede' => true,
]);
```


### `requireApproval`

| Value | Effect |
|---|---|
| `['add', 'edit']` | new records and edits go through approval, deletes save directly |
| `['edit']` | only modifications need approval; new records save freely |
| `['delete']` | only deletions need approval (rare but useful for retention) |
| `['add', 'edit', 'delete']` | every mutation queues |

### `validateOnDraft`

`true` runs the table's normal validation on the draft data before the
proposal is stored. Failures show up in the form before submission, which
is usually what the user wants.

`false` accepts any payload into the draft and only runs validation when
the admin approves. Use this when proposals are partial / WIP and you
don't want users blocked on incomplete data.

### `autoSupersede`

When a user has multiple pending drafts for the same source/primary key,
the queue gets noisy. With `autoSupersede` (default), each new proposal
marks the user's previous pending drafts as `superseded` automatically.
Switch to `false` to keep every proposal in the queue.

> Manual variant: `$bouncerTable->supersedeOthers($source, $primaryKey, $keepId)`.

## App config (`Bouncer.*`)

These live in `config/app.php` (or `app_local.php`). Defaults work — only
set what you need. See `config/app.example.php` for the full reference
with inline comments.

### `Bouncer.adminLayout`

Controls the layout used by the admin UI.

| Value | Behavior |
|---|---|
| `null` (default) | Use the plugin's self-contained Bootstrap 5 layout (`Bouncer.bouncer`). No dependency on host-app styling. |
| `false` | Disable the plugin layout entirely — render inside the app's default layout. |
| `string` | Use a named layout, e.g. `'Admin.default'` or `'MyTheme.admin'`, to integrate with an existing admin theme. |

### `Bouncer.standalone`

When `true`, the admin controller does not extend host `AppController`'s
auth/loadComponent behavior. Useful for projects that want the bouncer
admin to operate under a separate auth stack (e.g. a static admin token
or a different identity provider).

### `Bouncer.accessCheck`

Defense-in-depth gate for the admin UI. Optional — the host app's
`AppController` auth is already the first line of defense.

```php
use Cake\Core\Configure;
use Cake\Http\ServerRequest;

Configure::write('Bouncer.accessCheck', function (ServerRequest $request): bool {
    $identity = $request->getAttribute('identity');

    return $identity !== null
        && in_array('moderator', (array)$identity->roles, true);
});
```

The closure must return literal `true` to grant access — anything else
(returns `false`, returns a truthy non-bool, throws) yields a `403`. The
gate calls `Authorization::skipAuthorization()` when the
`cakephp/authorization` component is loaded so the policy layer doesn't
double-reject.

### `Bouncer.linkUser`

Renders user IDs in the admin UI as links to your app's user-view route.

```php
'Bouncer' => [
    'linkUser' => [
        'prefix' => 'Admin', 'controller' => 'Users', 'action' => 'view', '{user}',
    ],
],
```

Placeholders: `{user}` (the user ID).

Other forms accepted:
- String pattern: `'/admin/users/view/{user}'`
- Callable: `function ($userId, $userDisplay) { return '/admin/users/' . $userId; }`

### `Bouncer.linkRecord`

Renders source-record references in the admin UI as links to your app's
record-view routes. Plugin-aware.

```php
'Bouncer' => [
    'linkRecord' => function ($source, $primaryKey, $plugin, $tableName) {
        return [
            'plugin'     => $plugin ?: false,
            'prefix'     => 'Admin',
            'controller' => $tableName,
            'action'     => 'view',
            $primaryKey,
        ];
    },
],
```

Placeholders for the array/string forms: `{source}`, `{plugin}`, `{table}`,
`{primary_key}`.

## UUID Primary Keys

If your application uses UUID primary keys or UUID user IDs, the bundled
migration's integer columns won't match. Copy and adapt:

```bash
cp vendor/dereuromark/cakephp-bouncer/config/Migrations/*.php config/Migrations/
```

Then change column types as needed, e.g.:

```php
->addColumn('primary_key', 'uuid', [
    'default' => null,
    'null'    => true,
    'comment' => 'ID of record in source table, NULL for new records',
])
->addColumn('user_id', 'uuid', [
    'default' => null,
    'null'    => false,
])
->addColumn('reviewer_id', 'uuid', [
    'default' => null,
    'null'    => true,
])
```

Don't run the plugin's own migration when you've taken over with a copied
version — your app migration is now the source of truth.
