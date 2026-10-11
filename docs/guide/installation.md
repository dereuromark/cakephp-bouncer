# Installation

This development branch targets CakePHP 6 and PHP 8.5.

Ensure your application has the Migrations plugin installed and loaded. From
your CakePHP application directory:

```bash
composer config minimum-stability dev
composer config prefer-stable true
composer require cakephp/cakephp:^6.0@dev dereuromark/cakephp-bouncer:dev-cake6
bin/cake plugin load Bouncer
bin/cake migrations migrate -p Bouncer
```

The migrations create and update `bouncer_records`. Configure any non-default primary
key types before migrating; see [Configuration](configuration.md).

Loading Bouncer registers its Admin routes. The prefix itself provides no access
control. The plugin inherits your application's `AppController` checks, and
`Bouncer.accessCheck` can apply an explicit permission check. Configure access
before exposing the UI; see [Admin UI](../features/admin-ui.md#access-control).

To disable plugin routes, update the generated entry in `config/plugins.php`:

```php
'Bouncer' => ['routes' => false],
```

## Custom admin routes

The default queue is available at `/admin/bouncer`. To use your own route map,
disable plugin routes as shown above and add this block to `config/routes.php`:

```php
use Cake\Routing\Route\DashedRoute;
use Cake\Routing\RouteBuilder;

$routes->prefix('Admin', function (RouteBuilder $routes): void {
    $routes->plugin('Bouncer', ['path' => '/bouncer'], function (RouteBuilder $routes): void {
        $routes->setRouteClass(DashedRoute::class);
        $routes->connect('/pending', ['controller' => 'Bouncer', 'action' => 'index']);
        $routes->fallbacks();
    });
});
```

This maps the queue to `/admin/bouncer/pending`. Fallbacks provide controller
URLs such as `/admin/bouncer/bouncer/view/1`; they do not recreate the plugin's
short `/view/1` routes. See [Admin UI](../features/admin-ui.md) for the default map.

Follow [Getting started](index.md) to enable proposals for an existing table.

## Host callbacks on CakePHP 6

The host controller's `beforeFilter()` returns `void`. A redirect guard should set its response on the event, for example `$event->setResult($this->redirect('/login'))`. Bouncer preserves that response and stops later listeners. Standalone mode skips the host guard and requires an explicit `Bouncer.adminAccess` check.

Custom behaviors and helpers define their configuration defaults in `$defaultConfig`. Entity mass-assignment rules use `$patchable`, and marshalling options use `patchableFields`.
