<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Laravel\Console;

use Illuminate\Console\Command;
use Iprodev\EasyCache\Cache\MultiTierCache;

final class ClearCommand extends Command
{
    /** @var string */
    protected $signature = 'easycache:clear';

    /** @var string */
    protected $description = 'Delete every EasyCache entry from all tiers';

    public function handle(MultiTierCache $cache): int
    {
        if (!$cache->clear()) {
            $this->error('Some tiers could not be cleared, see the log for details.');
            return self::FAILURE;
        }

        $this->info('EasyCache cleared.');

        return self::SUCCESS;
    }
}
