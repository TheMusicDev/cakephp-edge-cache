<?php
declare(strict_types=1);

namespace TheMusicDev\EdgeCache;

use Cake\Core\Configure;
use Cake\Routing\RouteBuilder;
use InvalidArgumentException;
use TheMusicDev\EdgeCache\Middleware\FormMiddleware;
use TheMusicDev\EdgeCache\Middleware\PublicMiddleware;
use TheMusicDev\EdgeCache\Purge\CloudflarePurger;
use TheMusicDev\EdgeCache\Purge\NullPurger;
use TheMusicDev\EdgeCache\Purge\PurgerInterface;

/**
 * The two entry points a host touches: registering the route middleware, and getting the configured purger.
 */
final class EdgeCache
{
    /**
     * Register `edgePublic` and `edgeForm`. Call once in config/routes.php, then `applyMiddleware('edgePublic')` or
     * `applyMiddleware('edgeForm')` inside each scope. A route in neither is private and uncached.
     *
     * @param \Cake\Routing\RouteBuilder $routes The route builder.
     * @return void
     */
    public static function register(RouteBuilder $routes): void
    {
        $routes->registerMiddleware('edgePublic', new PublicMiddleware());
        $routes->registerMiddleware('edgeForm', new FormMiddleware());
    }

    /**
     * The purger for `EdgeCache.purge.driver`.
     *
     * @return \TheMusicDev\EdgeCache\Purge\PurgerInterface
     */
    public static function purger(): PurgerInterface
    {
        $config = (array)Configure::read('EdgeCache.purge');

        return match ($config['driver'] ?? 'null') {
            'null' => new NullPurger(),
            'cloudflare' => new CloudflarePurger((string)($config['zoneId'] ?? ''), (string)($config['token'] ?? '')),
            default => throw new InvalidArgumentException('Unknown EdgeCache.purge.driver.'),
        };
    }
}
