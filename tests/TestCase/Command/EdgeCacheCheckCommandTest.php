<?php
declare(strict_types=1);

namespace TheMusicDev\EdgeCache\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\Configure;
use Cake\Http\Client;
use Cake\Http\Client\Response;
use Cake\TestSuite\TestCase;

final class EdgeCacheCheckCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    private const TOKEN = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMN';
    private const ZONE = '0123456789abcdef0123456789abcdef';
    private const API = 'https://api.cloudflare.com/client/v4';

    private mixed $original;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setAppNamespace('TestApp');
        $this->original = Configure::read('EdgeCache.purge');
        Configure::write('EdgeCache.purge', ['driver' => 'cloudflare', 'zoneId' => self::ZONE, 'token' => self::TOKEN]);
        Configure::write('App.fullBaseUrl', 'https://example.test');
    }

    protected function tearDown(): void
    {
        Configure::write('EdgeCache.purge', $this->original);
        Client::clearMockResponses();
        parent::tearDown();
    }

    /**
     * @param string $method HTTP method.
     * @param string $path Path below /client/v4.
     * @param int $status HTTP status.
     * @param array<string, mixed> $json Answer body.
     * @return void
     */
    private function answer(string $method, string $path, int $status, array $json): void
    {
        Client::addMockResponse($method, self::API . $path, new Response(
            ['HTTP/1.1 ' . $status . ' X', 'Content-Type: application/json'],
            (string)json_encode($json),
        ));
    }

    private function everythingWorks(): void
    {
        $this->answer('GET', '/user/tokens/verify', 200, ['success' => true, 'result' => ['status' => 'active']]);
        $this->answer('POST', '/zones/' . self::ZONE . '/purge_cache', 200, ['success' => true]);
    }

    private function assertTokenNeverPrinted(): void
    {
        $this->assertStringNotContainsString(self::TOKEN, $this->_out->output() . $this->_err->output());
    }

    public function testAWorkingSetupSaysSoAndNeverPrintsTheToken(): void
    {
        $this->everythingWorks();

        $this->exec('edge_cache check');

        $this->assertExitSuccess();
        $this->assertOutputContains('valid and active');
        $this->assertOutputContains('Purge works');
        $this->assertOutputContains('https://example.test/__edge_cache_check');
        $this->assertTokenNeverPrinted();
    }

    public function testAnAccountOwnedTokenFailsVerifyButTheProbePurgeDecides(): void
    {
        $this->answer('GET', '/user/tokens/verify', 401, ['success' => false, 'errors' => [['code' => 1000, 'message' => 'Invalid API Token']]]);
        $this->answer('POST', '/zones/' . self::ZONE . '/purge_cache', 200, ['success' => true]);

        $this->exec('edge_cache check');

        $this->assertExitSuccess();
        $this->assertErrorContains('Token verify failed');
        $this->assertOutputContains('Purge works');
    }

    public function testARefusedPurgeExplainsTheCauseWithCloudflaresOwnWords(): void
    {
        $error = ['success' => false, 'errors' => [['code' => 10000, 'message' => 'Authentication error']]];
        $this->answer('GET', '/user/tokens/verify', 401, $error);
        $this->answer('POST', '/zones/' . self::ZONE . '/purge_cache', 401, $error);

        $this->exec('edge_cache check');

        $this->assertExitError();
        $this->assertErrorContains('HTTP 401');
        $this->assertErrorContains('Authentication error (10000)');
        $this->assertErrorContains('token itself is not accepted');
        $this->assertTokenNeverPrinted();
    }

    public function testAValidTokenWithoutPurgePermissionIsTold(): void
    {
        $this->answer('GET', '/user/tokens/verify', 200, ['success' => true, 'result' => ['status' => 'active']]);
        $this->answer('POST', '/zones/' . self::ZONE . '/purge_cache', 403, ['success' => false, 'errors' => []]);

        $this->exec('edge_cache check');

        $this->assertExitError();
        $this->assertErrorContains('Zone > Cache Purge');
        $this->assertErrorContains('Zone Resources');
        $this->assertErrorContains('not the account ID');
    }

    public function testBothCurrentAndOlderTokenFormatsPassTheShapeCheck(): void
    {
        foreach (['cfut_' . str_repeat('A', 48), 'cfat_' . str_repeat('a1', 24), self::TOKEN] as $token) {
            Configure::write('EdgeCache.purge', ['driver' => 'cloudflare', 'zoneId' => self::ZONE, 'token' => $token]);
            $this->everythingWorks();

            $this->exec('edge_cache check');

            $this->assertExitSuccess();
            $this->assertStringNotContainsString('does not look like', $this->_err->output());
            $this->assertStringNotContainsString($token, $this->_out->output() . $this->_err->output());
        }
    }

    public function testMissingCredentialsAreNamed(): void
    {
        Configure::write('EdgeCache.purge', ['driver' => 'null', 'zoneId' => null, 'token' => null]);

        $this->exec('edge_cache check');

        $this->assertExitError();
        $this->assertErrorContains('CLOUDFLARE_API_TOKEN, CLOUDFLARE_ZONE_ID');
    }

    public function testTheUsualTypingMistakesAreFlaggedBeforeAnyRequest(): void
    {
        Configure::write('EdgeCache.purge', [
            'driver' => 'cloudflare',
            'zoneId' => 'not-a-zone-id',
            'token' => '"Bearer ' . self::TOKEN . '"',
        ]);
        $this->answer('GET', '/user/tokens/verify', 401, ['success' => false]);
        $this->answer('POST', '/zones/not-a-zone-id/purge_cache', 404, ['success' => false]);

        $this->exec('edge_cache check');

        $this->assertExitError();
        $this->assertErrorContains('quote');
        $this->assertErrorContains('Bearer');
        $this->assertErrorContains('zone ID is 32 hex characters');
    }

    public function testItNeedsAUrlInTheZoneWhenThereIsNoBaseUrl(): void
    {
        Configure::write('App.fullBaseUrl', '');

        $this->exec('edge_cache check');

        $this->assertExitError();
        $this->assertErrorContains('--url');
    }
}
