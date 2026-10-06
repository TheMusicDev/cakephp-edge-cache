<?php
declare(strict_types=1);

namespace TheMusicDev\EdgeCache\Test\TestCase\Middleware;

use Cake\Core\Configure;
use Cake\Http\Exception\InvalidCsrfTokenException;
use Cake\Http\Response;
use Cake\TestSuite\TestCase;
use TheMusicDev\EdgeCache\Middleware\DefaultDenyMiddleware;
use TheMusicDev\EdgeCache\Middleware\FormMiddleware;

final class FormAndDefaultDenyTest extends TestCase
{
    use MiddlewareTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('EdgeCache.csrf', ['httponly' => true]);
    }

    public function testAFormPageGetsTheCsrfCookieAndIsNeverCached(): void
    {
        $response = $this->through(new FormMiddleware(), new Response());

        $this->assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
        // Cake keeps cookies in a collection until they are emitted.
        $cookie = $response->getCookieCollection()->get('csrfToken');
        $this->assertTrue($cookie->isHttpOnly());
    }

    public function testAFormPostWithoutATokenIsRefused(): void
    {
        $this->expectException(InvalidCsrfTokenException::class);

        $this->through(new FormMiddleware(), new Response(), ['environment' => ['REQUEST_METHOD' => 'POST']]);
    }

    public function testAResponseWithNoCacheControlBecomesPrivateAndUncached(): void
    {
        $response = $this->through(new DefaultDenyMiddleware(), new Response());

        $this->assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }

    public function testAResponseThatSetsItsOwnCacheControlIsLeftAlone(): void
    {
        $response = $this->through(
            new DefaultDenyMiddleware(),
            (new Response())->withHeader('Cache-Control', 'public, max-age=60'),
        );

        $this->assertSame('public, max-age=60', $response->getHeaderLine('Cache-Control'));
    }
}
