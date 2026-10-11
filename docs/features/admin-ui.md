# Admin UI

The plugin ships a self-contained admin interface for working through the
proposal queue.

## Routes

Loading the plugin registers `/admin/bouncer` as the proposal queue. Do not add
a second route block for the defaults. The plugin provides short routes for view,
approve, reject, and delete; the other actions use controller fallbacks:

| Path | Method | Action | Purpose |
|---|---|---|---|
| `/admin/bouncer` | GET | `index` | List proposals — filter by status, table, user |
| `/admin/bouncer/view/{id}` | GET | `view` | Show one proposal — side-by-side and inline diff, metadata, proposer note |
| `/admin/bouncer/bouncer/resolve/{id}` | GET | `resolve` | Combined approve/reject screen with reason text-area |
| `/admin/bouncer/approve/{id}` | POST | `approve` | Apply the proposed changes to the source table |
| `/admin/bouncer/reject/{id}` | POST | `reject` | Mark as rejected, store the reviewer's reason |
| `/admin/bouncer/bouncer/reopen/{id}` | POST | `reopen` | Move an approved / rejected / superseded row back to `pending` for re-review |
| `/admin/bouncer/delete/{id}` | POST | `delete` | Hard-delete the bouncer record entirely (audit-trail-aware: pair with AuditStash if you want to keep a deletion log) |
| `/admin/bouncer/bouncer/reset` | POST | `reset` | Truncate the entire bouncer queue — debug / dev tooling, gate behind a strict access check |

`reset` is the heavy hammer — it clears the whole `bouncer_records`
table. Don't expose it to anyone you wouldn't trust with a SQL `TRUNCATE`.
The default `Bouncer.accessCheck` doesn't separately gate it; use a
stricter closure or your authorization layer if your environment needs
that distinction.

## Access control

The plugin extends the host application's `AppController`. Neither the Admin
prefix nor loading the plugin alone authenticates a request. There are two places
to enforce access:

1. **Host `AppController` auth** — configure authentication and
   authorization for these plugin routes in the host `AppController` or middleware.
2. **`Bouncer.accessCheck`** (optional) — a closure for defense-in-depth or
   "moderators only, not all admins" carve-outs. See
   [Configuration → `Bouncer.accessCheck`](../guide/configuration#bouncer-accesscheck).

`Bouncer.accessCheck` must be a Closure that returns literal true to grant access.
If configured, the plugin skips the controller Authorization component's policy
check when that component provides `skipAuthorization()`. Make the full intended
permission decision in the Closure. There is no implemented `Bouncer.standalone`
switch that removes inheritance from the host `AppController`.

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
