<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Support;

trait TempDirectory
{
    /** @var list<string> */
    private array $tempDirectories = [];

    protected function makeTempDir(string $name = 'easycache-test'): string
    {
        $dir = sys_get_temp_dir() . '/' . $name . '-' . bin2hex(random_bytes(6));
        $this->tempDirectories[] = $dir;
        return $dir;
    }

    protected function removeTempDirs(): void
    {
        foreach ($this->tempDirectories as $dir) {
            self::removeRecursive($dir);
        }
        $this->tempDirectories = [];
    }

    private static function removeRecursive(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $item) {
            if ($item !== '.' && $item !== '..') {
                self::removeRecursive($path . '/' . $item);
            }
        }
        @rmdir($path);
    }
}
