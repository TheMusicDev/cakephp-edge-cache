<?php
declare(strict_types=1);

namespace TheMusicDev\EdgeCache\Purge;

use Cake\Http\Client;
use RuntimeException;

/**
 * Cloudflare cache purge (POST /zones/{zone}/purge_cache). The API token needs only the "Cache Purge" permission.
 */
final class CloudflarePurger implements PurgerInterface
{
    // The API takes at most 100 URLs per request.
    private const MAX_URLS = 100;

    private CloudflareApi $api;

    /**
     * @param string $zoneId The zone ID.
     * @param string $token The API token.
     * @param \Cake\Http\Client|null $client The HTTP client (a seam for tests).
     */
    public function __construct(private string $zoneId, string $token, ?Client $client = null)
    {
        if ($zoneId === '' || $token === '') {
            throw new RuntimeException(
                'EdgeCache: the Cloudflare purge needs CLOUDFLARE_ZONE_ID and CLOUDFLARE_API_TOKEN.',
            );
        }
        $this->api = new CloudflareApi($token, $client);
    }

    /**
     * @inheritDoc
     */
    public function urls(array $urls): void
    {
        foreach (array_chunk($urls, self::MAX_URLS) as $chunk) {
            $this->post(['files' => $chunk]);
        }
    }

    /**
     * @inheritDoc
     */
    public function everything(): void
    {
        $this->post(['purge_everything' => true]);
    }

    /**
     * @param array<string, mixed> $body The request body.
     * @return void
     */
    private function post(array $body): void
    {
        $answer = $this->api->post('/zones/' . rawurlencode($this->zoneId) . '/purge_cache', $body);
        if (!$answer['success']) {
            // Cloudflare's own error text is safe to show; the token and the request never are (logs, queue table).
            throw new RuntimeException(
                'EdgeCache: Cloudflare refused the purge (HTTP ' . $answer['status'] . ')'
                . ($answer['errors'] !== '' ? ': ' . $answer['errors'] : '')
                . '. Run `bin/cake edge_cache check` to find out why.',
            );
        }
    }
}
