<?php
declare(strict_types=1);

namespace TheMusicDev\EdgeCache\Command;

use Cake\Command\Command;
use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use TheMusicDev\EdgeCache\EdgeCache;

/**
 * `bin/cake edge_cache purge --all` or `bin/cake edge_cache purge --url /about --url /careers/x`: the deploy script and a queued
 * job (Queue.Execute) both run this, so the purge has one code path.
 */
final class EdgeCachePurgeCommand extends Command
{
    /**
     * @inheritDoc
     */
    public static function defaultName(): string
    {
        return 'edge_cache purge';
    }

    /**
     * @inheritDoc
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        return $parser
            ->setDescription('Remove pages from the CDN cache.')
            ->addOption('all', ['help' => 'Purge everything.', 'boolean' => true])
            ->addOption('url', [
                'help' => 'A path (/about) or absolute URL to purge; repeat for several.',
                'multiple' => true,
            ]);
    }

    /**
     * @inheritDoc
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $paths = (array)$args->getArrayOption('url');
        $all = (bool)$args->getOption('all');
        if (!$all && !$paths) {
            $io->err('Give --all or at least one --url.');

            return self::CODE_ERROR;
        }

        if (Configure::read('EdgeCache.purge.driver') === 'null') {
            $io->out('No purge driver configured (set CLOUDFLARE_API_TOKEN): nothing to purge.');

            return self::CODE_SUCCESS;
        }

        $purger = EdgeCache::purger();
        if ($all) {
            $purger->everything();
            $io->out('Purged everything.');

            return self::CODE_SUCCESS;
        }

        $base = rtrim((string)Configure::read('App.fullBaseUrl'), '/');
        $urls = array_values(array_map(
            fn(string $path): string => str_contains($path, '://') ? $path : $base . '/' . ltrim($path, '/'),
            $paths,
        ));
        $purger->urls($urls);
        $io->out('Purged ' . count($urls) . ' URL(s).');

        return self::CODE_SUCCESS;
    }
}
