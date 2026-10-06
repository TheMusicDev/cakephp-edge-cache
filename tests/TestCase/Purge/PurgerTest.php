<?php
declare(strict_types=1);

namespace TheMusicDev\EdgeCache\Test\TestCase\Purge;

use Cake\Core\Configure;
use Cake\Http\Client;
use Cake\Http\Client\Response;
use Cake\TestSuite\TestCase;
use InvalidArgumentException;
use RuntimeException;
use TheMusicDev\EdgeCache\EdgeCache;
use TheMusicDev\EdgeCache\Purge\CloudflarePurger;
use TheMusicDev\EdgeCache\Purge\NullPurger;

final class PurgerTest extends TestCase
{
    private const URL = 'https://api.cloudflare.com/client/v4/zones/zone123/purge_cache';

    /**
     * @var list<array<string, mixed>>
     */
    private array $bodies = [];

    protected function tearDown(): void
    {
        Client::clearMockResponses();
        parent::tearDown();
    }

    private function mockCloudflare(bool $ok = true): void
    {
        $this->bodies = [];
        $response = new Response(
            ['HTTP/1.1 ' . ($ok ? '200 OK' : '403 Forbidden'), 'Content-Type: application/json'],
            (string)json_encode(['success' => $ok]),
        );
        Client::addMockResponse('POST', self::URL, $response, ['match' => function ($request): bool {
            $this->bodies[] = json_decode((string)$request->getBody(), true);
            $this->assertSame('Bearer secret-token', $request->getHeaderLine('Authorization'));

            return true;
        }]);
    }

    public function testEverythingSendsPurgeEverything(): void
    {
        $this->mockCloudflare();

        (new CloudflarePurger('zone123', 'secret-token'))->everything();

        $this->assertSame([['purge_everything' => true]], $this->bodies);
    }

    public function testUrlsAreSentInChunksOfOneHundred(): void
    {
        $this->mockCloudflare();
        $urls = array_map(fn(int $n): string => "https://example.test/p$n", range(1, 150));

        (new CloudflarePurger('zone123', 'secret-token'))->urls($urls);

        $this->assertCount(2, $this->bodies);
        $this->assertCount(100, $this->bodies[0]['files']);
        $this->assertCount(50, $this->bodies[1]['files']);
    }

    public function testARefusedPurgeThrowsWithoutTheToken(): void
    {
        $this->mockCloudflare(false);

        try {
            (new CloudflarePurger('zone123', 'secret-token'))->everything();
            $this->fail('Expected a RuntimeException.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('403', $e->getMessage());
            $this->assertStringNotContainsString('secret-token', $e->getMessage());
        }
    }

    public function testMissingCredentialsFailLoudly(): void
    {
        $this->expectException(RuntimeException::class);

        new CloudflarePurger('', '');
    }

    public function testTheDriverComesFromConfig(): void
    {
        $original = Configure::read('EdgeCache.purge');
        try {
            Configure::write('EdgeCache.purge', ['driver' => 'null']);
            $this->assertInstanceOf(NullPurger::class, EdgeCache::purger());
            Configure::write('EdgeCache.purge', ['driver' => 'cloudflare', 'zoneId' => 'z', 'token' => 't']);
            $this->assertInstanceOf(CloudflarePurger::class, EdgeCache::purger());
            Configure::write('EdgeCache.purge', ['driver' => 'nope']);
            $this->expectException(InvalidArgumentException::class);
            EdgeCache::purger();
        } finally {
            Configure::write('EdgeCache.purge', $original);
        }
    }
}
