<?php
declare(strict_types=1);

namespace TheMusicDev\EdgeCache\Middleware;

use Cake\Core\Configure;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Log\Log;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Route middleware `edgePublic`: a page identical for every visitor. Cloudflare keeps it (until purged) through its own
 * header; the browser and any other CDN are told to revalidate. A response that sets a cookie is never cacheable by
 * a CDN, so one on a public route is a bug: it throws in debug and is made uncached (and logged) in production.
 */
final class PublicMiddleware implements MiddlewareInterface
{
    private const UNCACHED = 'private, no-store';

    /**
     * @inheritDoc
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);
        $cacheable = in_array($request->getMethod(), ['GET', 'HEAD'], true) && $response->getStatusCode() === 200;
        if (!$cacheable) {
            return $response->withHeader('Cache-Control', self::UNCACHED);
        }

        if ($this->setsCookie($response)) {
            $message = 'EdgeCache: a public route set a cookie, so no CDN can cache it: '
                . $request->getUri()->getPath() . '. Put the route in the edgeForm scope, or stop the cookie.';
            if (Configure::read('debug')) {
                throw new LogicException($message);
            }
            Log::error($message);

            return $response->withHeader('Cache-Control', self::UNCACHED);
        }

        $hosts = (array)Configure::read('EdgeCache.hosts');
        if (!in_array($request->getUri()->getHost(), $hosts, true)) {
            return $response->withHeader('Cache-Control', self::UNCACHED);
        }

        $public = (array)Configure::read('EdgeCache.public');
        $response = $response
            ->withHeader('Cache-Control', (string)$public['browser'])
            ->withHeader('Cloudflare-CDN-Cache-Control', 'max-age=' . (int)$public['edgeTtl']);

        if ($public['etag'] && $response instanceof Response && $request instanceof ServerRequest) {
            $response = $response->withEtag(md5((string)$response->getBody()));
            if ($response->isNotModified($request)) {
                return $response->withNotModified();
            }
        }

        return $response;
    }

    /**
     * Cake's Response keeps cookies in a collection, not in the headers, until they are emitted.
     *
     * @param \Psr\Http\Message\ResponseInterface $response The response.
     * @return bool
     */
    private function setsCookie(ResponseInterface $response): bool
    {
        return $response->hasHeader('Set-Cookie')
            || ($response instanceof Response && count($response->getCookieCollection()) > 0);
    }
}
