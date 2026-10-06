<?php
declare(strict_types=1);

namespace TheMusicDev\EdgeCache\Purge;

use Cake\Http\Client;
use Cake\Http\Client\Response;

/**
 * The two Cloudflare API calls the plugin makes, with the answer reduced to what a message needs. Nothing it returns
 * contains the token or the request, so the result can be printed and logged.
 */
final class CloudflareApi
{
    private const BASE = 'https://api.cloudflare.com/client/v4';

    private Client $client;

    /**
     * @param string $token The API token.
     * @param \Cake\Http\Client|null $client The HTTP client (a seam for tests).
     */
    public function __construct(private string $token, ?Client $client = null)
    {
        $this->client = $client ?? new Client();
    }

    /**
     * @param string $path Path below /client/v4, starting with a slash.
     * @return array{status: int, success: bool, errors: string, result: mixed}
     */
    public function get(string $path): array
    {
        return $this->reduce($this->client->get(self::BASE . $path, [], $this->options()));
    }

    /**
     * @param string $path Path below /client/v4, starting with a slash.
     * @param array<string, mixed> $body The JSON body.
     * @return array{status: int, success: bool, errors: string, result: mixed}
     */
    public function post(string $path, array $body): array
    {
        return $this->reduce($this->client->post(self::BASE . $path, (string)json_encode($body), $this->options() + [
            'type' => 'json',
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function options(): array
    {
        return ['headers' => ['Authorization' => 'Bearer ' . $this->token]];
    }

    /**
     * @param \Cake\Http\Client\Response $response The API response.
     * @return array{status: int, success: bool, errors: string, result: mixed}
     */
    private function reduce(Response $response): array
    {
        $json = (array)$response->getJson();
        $errors = array_map(
            fn(mixed $error): string => is_array($error)
                ? (string)($error['message'] ?? 'error') . ' (' . (string)($error['code'] ?? '?') . ')'
                : 'error',
            (array)($json['errors'] ?? []),
        );

        return [
            'status' => $response->getStatusCode(),
            'success' => $response->isOk() && !empty($json['success']),
            'errors' => implode('; ', $errors),
            'result' => $json['result'] ?? null,
        ];
    }
}
