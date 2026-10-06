<?php
declare(strict_types=1);

namespace TheMusicDev\EdgeCache\Test\TestCase\Middleware;

use Cake\Core\Configure;
use Cake\Http\Cookie\Cookie;
use Cake\Http\Response;
use Cake\Log\Log;
use Cake\TestSuite\TestCase;
use LogicException;
use TheMusicDev\EdgeCache\Middleware\PublicMiddleware;

final class PublicMiddlewareTest extends TestCase
{
    use MiddlewareTestTrait;

    private mixed $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = Configure::read('EdgeCache');
        Configure::write('EdgeCache', [
            'hosts' => [],
            'public' => ['edgeTtl' => 31536000, 'browser' => 'private, no-cache', 'etag' => true],
        ]);
    }

    protected function tearDown(): void
    {
        Configure::write('EdgeCache', $this->original);
        Configure::write('debug', true);
        parent::tearDown();
    }

    private function page(): Response
    {
        return (new Response())->withStringBody('<h1>About</h1>');
    }

    public function testAListedHostGetsTheTwoCacheHeadersAndAnEtag(): void
    {
        $this->listHost();

        $response = $this->through(new PublicMiddleware(), $this->page(), $this->onListedHost());

        $this->assertSame('private, no-cache', $response->getHeaderLine('Cache-Control'));
        $this->assertSame('max-age=31536000', $response->getHeaderLine('Cloudflare-CDN-Cache-Control'));
        $this->assertSame('"' . md5('<h1>About</h1>') . '"', $response->getHeaderLine('Etag'));
    }

    public function testAnUnlistedHostIsNeverCachedAtTheEdge(): void
    {
        $response = $this->through(new PublicMiddleware(), $this->page());

        $this->assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        $this->assertFalse($response->hasHeader('Cloudflare-CDN-Cache-Control'));
    }

    public function testAMatchingIfNoneMatchGetsAnEmpty304ThatKeepsTheCacheHeaders(): void
    {
        $this->listHost();
        $request = $this->onListedHost() + [];
        $request['environment']['HTTP_IF_NONE_MATCH'] = '"' . md5('<h1>About</h1>') . '"';

        $response = $this->through(new PublicMiddleware(), $this->page(), $request);

        $this->assertSame(304, $response->getStatusCode());
        $this->assertSame('', (string)$response->getBody());
        $this->assertSame('max-age=31536000', $response->getHeaderLine('Cloudflare-CDN-Cache-Control'));
    }

    public function testAStaleIfNoneMatchGetsTheFullPage(): void
    {
        $this->listHost();
        $request = $this->onListedHost();
        $request['environment']['HTTP_IF_NONE_MATCH'] = '"old"';

        $response = $this->through(new PublicMiddleware(), $this->page(), $request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('<h1>About</h1>', (string)$response->getBody());
    }

    public function testEtagCanBeTurnedOff(): void
    {
        $this->listHost();
        Configure::write('EdgeCache.public.etag', false);

        $response = $this->through(new PublicMiddleware(), $this->page(), $this->onListedHost());

        $this->assertFalse($response->hasHeader('Etag'));
        $this->assertSame('max-age=31536000', $response->getHeaderLine('Cloudflare-CDN-Cache-Control'));
    }

    public function testPostsAndNon200ResponsesAreNeverCached(): void
    {
        $this->listHost();

        $post = $this->through(new PublicMiddleware(), $this->page(), $this->onListedHost('POST'));
        $missing = $this->through(new PublicMiddleware(), $this->page()->withStatus(404), $this->onListedHost());

        foreach ([$post, $missing] as $response) {
            $this->assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
            $this->assertFalse($response->hasHeader('Cloudflare-CDN-Cache-Control'));
        }
    }

    public function testACookieOnAPublicRouteThrowsInDebug(): void
    {
        $this->listHost();
        $cookie = new Cookie('csrfToken', 'x');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('/about');

        $this->through(new PublicMiddleware(), $this->page()->withCookie($cookie), $this->onListedHost());
    }

    public function testACookieOnAPublicRouteIsUncachedAndLoggedInProduction(): void
    {
        $this->listHost();
        Configure::write('debug', false);
        Log::setConfig('edge_cache_test', ['className' => 'Array', 'levels' => ['error']]);

        try {
            $response = $this->through(
                new PublicMiddleware(),
                $this->page()->withCookie(new Cookie('csrfToken', 'x')),
                $this->onListedHost(),
            );

            $this->assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
            $this->assertFalse($response->hasHeader('Cloudflare-CDN-Cache-Control'));
            $this->assertStringContainsString('/about', Log::engine('edge_cache_test')->read()[0]);
        } finally {
            Log::drop('edge_cache_test');
        }
    }
}
