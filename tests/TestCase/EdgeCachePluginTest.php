<?php
declare(strict_types=1);

namespace TheMusicDev\EdgeCache\Test\TestCase;

use Cake\Core\Configure;
use Cake\Http\MiddlewareQueue;
use Cake\Routing\RouteBuilder;
use Cake\Routing\Router;
use Cake\TestSuite\TestCase;
use TestApp\Application;
use TheMusicDev\EdgeCache\EdgeCache;
use TheMusicDev\EdgeCache\Middleware\DefaultDenyMiddleware;

/**
 * The plugin loads in an application, merges its defaults under the host's values, and puts default-deny first.
 */
final class EdgeCachePluginTest extends TestCase
{
    private mixed $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = Configure::read('EdgeCache');
        Configure::delete('EdgeCache');
    }

    protected function tearDown(): void
    {
        Configure::write('EdgeCache', $this->original);
        parent::tearDown();
    }

    private function boot(): void
    {
        $app = new Application(CONFIG);
        $app->bootstrap();
        $app->pluginBootstrap();
    }

    public function testDefaultsCacheNothingAndPurgeNothing(): void
    {
        $this->boot();

        $this->assertSame([], Configure::read('EdgeCache.hosts'));
        $this->assertSame(31536000, Configure::read('EdgeCache.public.edgeTtl'));
        $this->assertSame('private, no-cache', Configure::read('EdgeCache.public.browser'));
        $this->assertTrue(Configure::read('EdgeCache.csrf.httponly'));
        $this->assertSame('null', Configure::read('EdgeCache.purge.driver'));
    }

    public function testHostValuesWinAndGroupsMergeOneLevelDeeper(): void
    {
        Configure::write('EdgeCache', ['hosts' => ['example.test'], 'public' => ['edgeTtl' => 60]]);

        $this->boot();

        $this->assertSame(['example.test'], Configure::read('EdgeCache.hosts'));
        $this->assertSame(60, Configure::read('EdgeCache.public.edgeTtl'));
        $this->assertSame('private, no-cache', Configure::read('EdgeCache.public.browser'));
    }

    public function testDefaultDenyIsTheOutermostLayer(): void
    {
        $app = new Application(CONFIG);
        $app->bootstrap();
        $app->pluginBootstrap();
        $queue = $app->pluginMiddleware(new MiddlewareQueue());
        $queue->rewind();

        $this->assertInstanceOf(DefaultDenyMiddleware::class, $queue->current());
    }

    public function testRegisterMakesBothRouteMiddlewareAvailableToAScope(): void
    {
        $routes = Router::createRouteBuilder('/');
        EdgeCache::register($routes);

        $routes->scope('/', function (RouteBuilder $scope): void {
            $scope->applyMiddleware('edgePublic', 'edgeForm');
            $scope->connect('/x', ['controller' => 'Pages', 'action' => 'x']);
        });

        $this->assertSame(['edgePublic', 'edgeForm'], Router::routes()[0]->getMiddleware());
    }
}
