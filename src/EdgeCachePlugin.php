<?php
declare(strict_types=1);

namespace TheMusicDev\EdgeCache;

use Cake\Core\BasePlugin;
use Cake\Http\MiddlewareQueue;
use TheMusicDev\EdgeCache\Middleware\DefaultDenyMiddleware;

/**
 * TheMusicDev/EdgeCache: cache headers for a site behind a CDN. Everything is private and uncached unless a route
 * opts in, so a forgotten route is slow, never leaked. Configuration lives in config/app_default.php and
 * config/bootstrap.php, merged UNDER the host's `EdgeCache.*` values.
 */
final class EdgeCachePlugin extends BasePlugin
{
    /**
     * Put the default-deny layer outermost, before the error handler, so even error pages carry a Cache-Control.
     *
     * @param \Cake\Http\MiddlewareQueue $middlewareQueue The queue.
     * @return \Cake\Http\MiddlewareQueue
     */
    public function middleware(MiddlewareQueue $middlewareQueue): MiddlewareQueue
    {
        return $middlewareQueue->prepend(new DefaultDenyMiddleware());
    }
}
