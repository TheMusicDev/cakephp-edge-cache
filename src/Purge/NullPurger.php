<?php
declare(strict_types=1);

namespace TheMusicDev\EdgeCache\Purge;

/**
 * Staging, local development and tests: nothing is held, so nothing is purged.
 */
final class NullPurger implements PurgerInterface
{
    /**
     * @inheritDoc
     */
    public function urls(array $urls): void
    {
    }

    /**
     * @inheritDoc
     */
    public function everything(): void
    {
    }
}
