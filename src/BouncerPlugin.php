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
        $routes->plugin(
            'Bouncer',
            ['path' => '/bouncer'],
            function (RouteBuilder $builder): void {
                $builder->setExtensions(['json']);

                $builder->prefix('Admin', function (RouteBuilder $routes): void {
                    $routes->setRouteClass(DashedRoute::class);
                    $routes->fallbacks();
                });

                // Note: csrf middleware should be registered in Application.php if needed
                $builder->fallbacks();
            },
        );
    }
}
