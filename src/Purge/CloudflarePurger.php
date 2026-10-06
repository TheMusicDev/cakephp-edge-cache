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

    private Client $client;

    /**
     * @param string $zoneId The zone ID.
     * @param string $token The API token.
     */
    public function __construct(private string $zoneId, private string $token)
    {
        if ($zoneId === '' || $token === '') {
            throw new RuntimeException(
                'EdgeCache: the Cloudflare purge needs CLOUDFLARE_ZONE_ID and CLOUDFLARE_API_TOKEN.',
            );
        }
        $this->client = new Client();
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
        $response = $this->client->post(
            'https://api.cloudflare.com/client/v4/zones/' . rawurlencode($this->zoneId) . '/purge_cache',
            (string)json_encode($body),
            ['type' => 'json', 'headers' => ['Authorization' => 'Bearer ' . $this->token]],
        );
        $json = $response->getJson();
        if (!$response->isOk() || empty($json['success'])) {
            // Never put the token or the request in the message: it ends up in logs and the queue table.
            throw new RuntimeException(
                'EdgeCache: Cloudflare refused the purge (HTTP ' . $response->getStatusCode() . ').',
            );
        }
    }
}
