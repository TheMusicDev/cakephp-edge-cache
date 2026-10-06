<?php
declare(strict_types=1);

namespace TheMusicDev\EdgeCache\Middleware;

use Cake\Core\Configure;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Route middleware `edgeForm`: CSRF protection (cookie and check) for the routes that have a form, and never cached.
 * The page prints a per-visitor token, so a shared copy would hand everyone the same one.
 */
final class FormMiddleware implements MiddlewareInterface
{
    /**
     * @inheritDoc
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $csrf = new CsrfProtectionMiddleware((array)Configure::read('EdgeCache.csrf'));

        return $csrf->process($request, $handler)->withHeader('Cache-Control', 'private, no-store');
    }
}
