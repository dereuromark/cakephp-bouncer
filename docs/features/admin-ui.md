# Admin UI

The plugin ships a self-contained admin interface for working through the
proposal queue.

## Routes

Mount the plugin under your admin prefix:

```php
$routes->prefix('Admin', function (RouteBuilder $routes) {
    $routes->plugin('Bouncer', function (RouteBuilder $routes) {
        $routes->connect('/pending', ['controller' => 'Bouncer', 'action' => 'index']);
        $routes->fallbacks();
    });
});
```

`/admin/bouncer/bouncer` is the queue. `fallbacks()` wires the rest:

| Path | Action |
|---|---|
| `/admin/bouncer/bouncer` | List pending / approved / rejected records, filter by status, table, user |
| `/admin/bouncer/bouncer/view/{id}` | Side-by-side and inline diff of one proposal |
| `/admin/bouncer/bouncer/approve/{id}` | Approve — applies changes to the source table |
| `/admin/bouncer/bouncer/reject/{id}` | Reject with optional reason |

## Access control

Two layers, both optional:

1. **Host `AppController` auth** — your existing admin gate already protects
   `/admin/*`. Usually that's all you need.
2. **`Bouncer.accessCheck`** (optional) — a closure for defense-in-depth or
   "moderators only, not all admins" carve-outs. See
   [Configuration → `Bouncer.accessCheck`](../guide/configuration#bouncer-accesscheck).

If you want the plugin admin to operate **outside** your host auth stack
entirely (separate token, separate identity provider), set
`Bouncer.standalone => true` and use `accessCheck` as the actual gate.

## Admin layout

`Bouncer.adminLayout` controls the layout used by the admin pages — see
[Configuration → `Bouncer.adminLayout`](../guide/configuration#bouncer-adminlayout)
for the three modes (plugin self-contained, app default, named layout).

## Linking users and records

The admin viewer can render user IDs and record references as links into
your app. Configure once in `app.php`:

```php
'Bouncer' => [
    'linkUser' => [
        'prefix' => 'Admin', 'controller' => 'Users', 'action' => 'view', '{user}',
    ],
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

Available placeholders are listed in
[Configuration → `Bouncer.linkRecord`](../guide/configuration#bouncer-linkrecord).

The `linkRecord` callable form is the most flexible — it gets the source
string (`Articles` or `Community.Stories`), the plugin name (`Community`
or empty), the bare table name (`Stories`), and the primary key, so it
works seamlessly across plugin boundaries.

## User display names

Bouncer records carry optional `user_display` and `reviewer_display`
columns. Populating them lets the admin UI show "Alice Smith" instead of
`User #42`. The simplest way is to set them when the draft is created —
either in your save flow before calling `save()`, or by extending
`BouncerBehavior::handleDraftCreation()`.

```php
// In your controller before save:
$article->set('_meta', [
    'user_display' => $this->Authentication->getIdentity()->getOriginalData()->display_name,
]);
```

(How exactly you wire this depends on your identity stack — the helper
will use whatever is on the `bouncer_record` row at render time.)

## Approving and rejecting

The approve action runs `BouncerBehavior::applyApprovedChanges()` on the
source table. That call is transactional — if the apply fails (validation,
rules, missing source row, foreign-key violation), the bouncer record stays
in `pending` state and the admin sees the failure flash.

Reject lets the admin attach a reason that's stored on the record and
shown to the proposer.
