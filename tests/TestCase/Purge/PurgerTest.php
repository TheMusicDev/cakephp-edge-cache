<?php
declare(strict_types=1);

namespace TheMusicDev\EdgeCache\Test\TestCase\Purge;

use Cake\Core\Configure;
use Cake\Http\Client;
use Cake\Http\Client\AdapterInterface;
use Cake\Http\Client\Response;
use Cake\TestSuite\TestCase;
use InvalidArgumentException;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use TheMusicDev\EdgeCache\EdgeCache;
use TheMusicDev\EdgeCache\Purge\CloudflarePurger;
use TheMusicDev\EdgeCache\Purge\NullPurger;

final class PurgerTest extends TestCase
{
    private const URL = 'https://api.cloudflare.com/client/v4/zones/zone123/purge_cache';

    /**
     * A client whose adapter records the requests it was asked to send and answers like Cloudflare.
     *
     * @param bool $ok Whether Cloudflare accepts the purge.
     * @param list<\Psr\Http\Message\RequestInterface> $sent Filled with the requests.
     * @return \Cake\Http\Client
     */
    private function client(bool $ok, array &$sent): Client
    {
        $adapter = new class ($ok, $sent) implements AdapterInterface {
            /**
             * @param bool $ok Whether to answer success.
             * @param array<int, \Psr\Http\Message\RequestInterface> $sent Requests, by reference.
             */
            public function __construct(private bool $ok, private array &$sent)
            {
            }

            /**
             * @inheritDoc
             */
            public function send(RequestInterface $request, array $options): array
            {
                $this->sent[] = $request;

                return [new Response(
                    ['HTTP/1.1 ' . ($this->ok ? '200 OK' : '403 Forbidden'), 'Content-Type: application/json'],
                    (string)json_encode(['success' => $this->ok]),
                )];
            }
        };

        return new Client(['adapter' => $adapter]);
    }

    public function testEverythingSendsPurgeEverything(): void
    {
        $sent = [];

        (new CloudflarePurger('zone123', 'secret-token', $this->client(true, $sent)))->everything();

        $this->assertCount(1, $sent);
        $this->assertSame(self::URL, (string)$sent[0]->getUri());
        $this->assertSame('Bearer secret-token', $sent[0]->getHeaderLine('Authorization'));
        $this->assertSame(['purge_everything' => true], json_decode((string)$sent[0]->getBody(), true));
    }

    public function testUrlsAreSentInChunksOfOneHundred(): void
    {
        $sent = [];
        $urls = array_map(fn(int $n): string => "https://example.test/p$n", range(1, 150));

        (new CloudflarePurger('zone123', 'secret-token', $this->client(true, $sent)))->urls($urls);

        $this->assertCount(2, $sent);
        $this->assertCount(100, json_decode((string)$sent[0]->getBody(), true)['files']);
        $this->assertCount(50, json_decode((string)$sent[1]->getBody(), true)['files']);
    }

    public function testARefusedPurgeThrowsWithoutTheToken(): void
    {
        $sent = [];

        try {
            (new CloudflarePurger('zone123', 'secret-token', $this->client(false, $sent)))->everything();
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
