<?php
declare(strict_types=1);

namespace TheMusicDev\EdgeCache\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\Configure;
use Cake\Http\Client;
use Cake\Http\Client\Response;
use Cake\TestSuite\TestCase;

final class EdgeCachePurgeCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setAppNamespace('TestApp');
        Configure::write('EdgeCache.purge', ['driver' => 'null']);
    }

    public function testItNeedsAFlagOrAUrl(): void
    {
        $this->exec('edge_cache purge');

        $this->assertExitError();
        $this->assertErrorContains('--all');
    }

    public function testAllAndUrlsRunThroughTheConfiguredPurger(): void
    {
        Client::addMockResponse(
            'POST',
            'https://api.cloudflare.com/client/v4/zones/z/purge_cache',
            new Response(['HTTP/1.1 200 OK'], '{"success":true}'),
        );
        Configure::write('EdgeCache.purge', ['driver' => 'cloudflare', 'zoneId' => 'z', 'token' => 't']);

        $this->exec('edge_cache purge --all');
        $this->assertExitSuccess();
        $this->assertOutputContains('Purged everything.');

        $this->exec('edge_cache purge --url /about --url https://example.test/x');
        $this->assertExitSuccess();
        $this->assertOutputContains('Purged 2 URL(s).');
        Client::clearMockResponses();
    }

    public function testWithoutADriverItSaysSoAndSucceeds(): void
    {
        $this->exec('edge_cache purge --all');

        $this->assertExitSuccess();
        $this->assertOutputContains('nothing to purge');
    }
}
