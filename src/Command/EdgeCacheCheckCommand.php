<?php
declare(strict_types=1);

namespace TheMusicDev\EdgeCache\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use TheMusicDev\EdgeCache\Purge\CloudflareApi;
use Throwable;

/**
 * `bin/cake edge_cache check`: does the Cloudflare configuration on this machine actually work? It reads the
 * credentials the same way a purge does, looks for the usual typing mistakes, asks Cloudflare whether the token is
 * valid, and finally purges one URL that does not exist: harmless, and it succeeds only when the token may purge this
 * zone, which is the one thing that matters. The token is never printed.
 */
final class EdgeCacheCheckCommand extends Command
{
    /**
     * @inheritDoc
     */
    public static function defaultName(): string
    {
        return 'edge_cache check';
    }

    /**
     * @inheritDoc
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Check that the Cloudflare credentials work, by purging one URL that does not exist.')
            ->addOption('url', [
                'help' => 'The URL to purge for the test; must belong to the zone. '
                    . 'Default: <App.fullBaseUrl>/__edge_cache_check.',
            ]);
    }

    /**
     * @inheritDoc
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $config = (array)Configure::read('EdgeCache.purge');
        $token = (string)($config['token'] ?? '');
        $zoneId = (string)($config['zoneId'] ?? '');

        $missing = array_keys(array_filter([
            'CLOUDFLARE_API_TOKEN' => $token === '',
            'CLOUDFLARE_ZONE_ID' => $zoneId === '',
        ]));
        if ($missing) {
            $io->err('FAIL  Not set: ' . implode(', ', $missing)
                . '. Put them in config/.env (see config/.env.example).');

            return self::CODE_ERROR;
        }
        $io->out(sprintf(
            'OK    Credentials are set (token %d characters, zone ID %d characters).',
            strlen($token),
            strlen($zoneId),
        ));
        foreach ($this->hints($token, $zoneId) as $hint) {
            $io->warning('WARN  ' . $hint);
        }

        $base = rtrim((string)Configure::read('App.fullBaseUrl'), '/');
        $probe = (string)($args->getOption('url') ?? $base . '/__edge_cache_check');
        if (!str_contains($probe, '://')) {
            $io->err('FAIL  No URL to test with: App.fullBaseUrl is empty. Pass --url https://your-domain/x');

            return self::CODE_ERROR;
        }

        $api = new CloudflareApi($token);
        try {
            $verify = $api->get('/user/tokens/verify');
            $this->reportVerify($io, $verify);

            $purge = $api->post('/zones/' . rawurlencode($zoneId) . '/purge_cache', ['files' => [$probe]]);
        } catch (Throwable $e) {
            $io->err('FAIL  Could not reach the Cloudflare API: ' . $e->getMessage());

            return self::CODE_ERROR;
        }

        if ($purge['success']) {
            $io->out('OK    Purge works: Cloudflare accepted a purge of ' . $probe . ' for this zone.');

            return self::CODE_SUCCESS;
        }

        $io->err('FAIL  Cloudflare refused the test purge (HTTP ' . $purge['status'] . ')'
            . ($purge['errors'] !== '' ? ': ' . $purge['errors'] : '') . '.');
        $io->err('      ' . $this->explain($purge['status'], $verify['success']));

        return self::CODE_ERROR;
    }

    /**
     * Typing mistakes that show up as a 401, found without sending the token anywhere.
     *
     * @param string $token The token.
     * @param string $zoneId The zone ID.
     * @return list<string>
     */
    private function hints(string $token, string $zoneId): array
    {
        $hints = [];
        if ($token !== trim($token) || preg_match('/[\s"\']/', $token)) {
            $hints[] = 'The token contains a space, a newline or a quote: remove it from config/.env.';
        }
        if (stripos(trim($token, " \t\r\n\"'"), 'bearer ') === 0) {
            $hints[] = 'The token starts with "Bearer ": the plugin adds that itself, remove it.';
        }
        if (preg_match('/^[0-9a-f]{37}$/', $token)) {
            $hints[] = 'This looks like the Global API Key (37 hex characters), not an API token (40 characters).';
        } elseif (!preg_match('/^(cf(ut|at)_[A-Za-z0-9]{48}|[A-Za-z0-9_-]{40})$/', $token)) {
            $hints[] = 'This does not look like a Cloudflare API token (current: "cfut_" or "cfat_" and 48 more '
                . 'characters; older: 40 characters); check it was copied whole.';
        }
        if (!preg_match('/^[0-9a-f]{32}$/', $zoneId)) {
            $hints[] = 'A zone ID is 32 hex characters (zone Overview page, right column).';
        }

        return $hints;
    }

    /**
     * @param \Cake\Console\ConsoleIo $io The console.
     * @param array{status: int, success: bool, errors: string, result: mixed} $verify The verify answer.
     * @return void
     */
    private function reportVerify(ConsoleIo $io, array $verify): void
    {
        $status = is_array($verify['result']) ? (string)($verify['result']['status'] ?? '') : '';
        if ($verify['success'] && $status === 'active') {
            $io->out('OK    Cloudflare says the token is valid and active.');

            return;
        }
        $reason = $verify['errors'] !== '' ? ': ' . $verify['errors'] : '';
        $io->warning('WARN  Token verify failed (HTTP ' . $verify['status'] . $reason . '). An account-owned token '
            . 'is verified elsewhere, so this alone is not conclusive; the purge below decides.');
    }

    /**
     * @param int $status The HTTP status of the refused purge.
     * @param bool $tokenVerified Whether /user/tokens/verify accepted the token.
     * @return string
     */
    private function explain(int $status, bool $tokenVerified): string
    {
        return match (true) {
            $status === 401 && !$tokenVerified => 'The token itself is not accepted: wrong value, deleted or rolled '
                . 'in the dashboard, or a Global API Key pasted as a token. Create a new one '
                . '(My Profile > API Tokens) and replace it in config/.env.',
            $status === 401, $status === 403 => 'The token is valid, but Cloudflare will not let it purge this zone. '
                . 'Check, in this order: (1) its permission is Zone > Cache Purge > Purge; (2) its Zone Resources '
                . 'include this zone; (3) CLOUDFLARE_ZONE_ID is this domain\'s zone ID (zone Overview page, right '
                . 'column), not the account ID or another domain\'s.',
            $status === 400 => 'Cloudflare rejected the request: the URL probably does not belong to the zone; '
                . 'pass --url.',
            $status === 404 => 'The zone ID is wrong, or the token cannot see that zone.',
            $status === 429 => 'Rate limited (the free plan allows 5 purge requests a minute); wait and retry.',
            default => 'See the error text above.',
        };
    }
}
