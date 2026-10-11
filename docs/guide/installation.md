# Installation

Ensure your application has the Migrations plugin installed and loaded. From
your CakePHP application directory:

```bash
composer require dereuromark/cakephp-bouncer
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
