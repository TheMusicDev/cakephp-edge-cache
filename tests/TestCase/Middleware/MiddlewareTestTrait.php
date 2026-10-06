<?php
declare(strict_types=1);

namespace TheMusicDev\EdgeCache\Test\TestCase\Middleware;

use Cake\Core\Configure;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Runs one middleware around a fixed response.
 */
trait MiddlewareTestTrait
{
    /**
     * @param \Psr\Http\Server\MiddlewareInterface $middleware The middleware.
     * @param \Cake\Http\Response $inner What the app returns.
     * @param array<string, mixed> $request Request config for ServerRequest.
     * @return \Psr\Http\Message\ResponseInterface
     */
    private function through(MiddlewareInterface $middleware, Response $inner, array $request = []): ResponseInterface
    {
        $handler = new class ($inner) implements RequestHandlerInterface {
            public function __construct(private Response $response)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        return $middleware->process(new ServerRequest($request + ['url' => '/about']), $handler);
    }

    /**
     * @return void
     */
    private function listHost(): void
    {
        Configure::write('EdgeCache.hosts', ['example.test']);
    }

    /**
     * @param string $method HTTP method.
     * @return array<string, mixed>
     */
    private function onListedHost(string $method = 'GET'): array
    {
        return [
            'environment' => ['REQUEST_METHOD' => $method, 'HTTP_HOST' => 'example.test'],
            'url' => '/about',
        ];
    }
}
