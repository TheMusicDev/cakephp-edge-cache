<?php
declare(strict_types=1);

namespace TheMusicDev\EdgeCache\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Outermost layer: a response that sets no Cache-Control of its own is private and uncached. Without it a CDN may keep
 * a response that has no cookie and no header, such as a gated download or a plugin's route.
 */
final class DefaultDenyMiddleware implements MiddlewareInterface
{
    /**
     * @inheritDoc
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        if ($response->hasHeader('Cache-Control')) {
            return $response;
        }

        return $response->withHeader('Cache-Control', 'private, no-store');
    }
}
