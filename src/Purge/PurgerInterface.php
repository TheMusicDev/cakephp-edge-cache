<?php
declare(strict_types=1);

namespace TheMusicDev\EdgeCache\Purge;

/**
 * Removes pages from the CDN that holds them. Throws when the CDN refuses, so a queued purge fails and is retried.
 */
interface PurgerInterface
{
    /**
     * @param list<string> $urls Absolute URLs.
     * @return void
     */
    public function urls(array $urls): void;

    /**
     * @return void
     */
    public function everything(): void;
}
