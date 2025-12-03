<?php

declare(strict_types=1);

namespace Bouncer;

use Cake\Core\BasePlugin;
use Cake\Routing\Route\DashedRoute;
use Cake\Routing\RouteBuilder;

/**
 * Plugin for Bouncer
 */
class BouncerPlugin extends BasePlugin
{
    /**
     * Do bootstrapping or not
     *
     * @var bool
     */
    protected bool $bootstrapEnabled = true;

    /**
     * Console middleware
     *
     * @var bool
     */
    protected bool $consoleEnabled = true;

    /**
     * Enable middleware
     *
     * @var bool
     */
    protected bool $middlewareEnabled = true;

    /**
     * Add routes for the plugin.
     *
     * @param \Cake\Routing\RouteBuilder $routes The route builder to update.
     *
     * @return void
     */
    public function routes(RouteBuilder $routes): void
    {
        $routes->prefix('Admin', function (RouteBuilder $routes): void {
            $routes->plugin('Bouncer', ['path' => '/bouncer'], function (RouteBuilder $routes): void {
                $routes->setRouteClass(DashedRoute::class);

                $routes->connect('/', ['controller' => 'Bouncer', 'action' => 'index']);
                $routes->connect('/view/{id}', ['controller' => 'Bouncer', 'action' => 'view'])
                    ->setPass(['id'])
                    ->setPatterns(['id' => '[0-9]+']);
                $routes->connect('/approve/{id}', ['controller' => 'Bouncer', 'action' => 'approve'])
                    ->setPass(['id'])
                    ->setPatterns(['id' => '[0-9]+']);
                $routes->connect('/reject/{id}', ['controller' => 'Bouncer', 'action' => 'reject'])
                    ->setPass(['id'])
                    ->setPatterns(['id' => '[0-9]+']);
                $routes->connect('/delete/{id}', ['controller' => 'Bouncer', 'action' => 'delete'])
                    ->setPass(['id'])
                    ->setPatterns(['id' => '[0-9]+']);

                $routes->fallbacks();
            });
        });
    }
}
