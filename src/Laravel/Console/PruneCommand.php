<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Laravel\Console;

use Illuminate\Console\Command;
use Iprodev\EasyCache\Cache\MultiTierCache;

final class PruneCommand extends Command
{
    /** @var string */
    protected $signature = 'easycache:prune';

    /** @var string */
    protected $description = 'Delete expired EasyCache entries (file and PDO tiers)';

    public function handle(MultiTierCache $cache): int
    {
        $count = $cache->prune();
        $this->info("Pruned {$count} expired entries.");

        return self::SUCCESS;
    }
}
