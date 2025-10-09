<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Iprodev\EasyCache\Cache\MultiTierCache;
use Iprodev\EasyCache\Storage\FileStorage;

final class KeyRulesTest extends TestCase
{
    public function testInvalidKeyThrows(): void
    {
        $c = new MultiTierCache([ new FileStorage(sys_get_temp_dir()) ]);
        $this->expectException(\Iprodev\EasyCache\Exceptions\InvalidArgument::class);
        $c->get('bad:key');
    }
}
