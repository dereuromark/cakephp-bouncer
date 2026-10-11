# Installation

From your CakePHP application directory:

```bash
composer require dereuromark/cakephp-bouncer
bin/cake plugin load Bouncer
bin/cake migrations migrate -p Bouncer
```

Ensure your application has the Migrations plugin installed and loaded. The
migrations create and update `bouncer_records`. Configure any non-default primary
key types before migrating; see [Configuration](configuration.md).

Loading Bouncer registers its Admin routes. The prefix itself provides no access
control. The plugin inherits your application's `AppController` checks, and
`Bouncer.accessCheck` can apply an explicit permission check. Configure access
before exposing the UI; see [Admin UI](../features/admin-ui.md#access-control).

To disable plugin routes, update the generated entry in `config/plugins.php`:

```php
'Bouncer' => ['routes' => false],
```

Follow [Getting started](index.md) to enable proposals for an existing table.
