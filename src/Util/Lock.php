<?php
declare(strict_types=1);

namespace Iprodev\EasyCache\Util;

final class Lock
{
    private string $path;
    private $fp;

    public function __construct(string $path) { $this->path = $path; }

    public function acquire(bool $blocking = true): bool
    {
        $this->fp = @fopen($this->path, 'c+');
        if (!$this->fp) return false;
        $flag = $blocking ? LOCK_EX : (LOCK_EX | LOCK_NB);
        return @flock($this->fp, $flag);
    }

    public function release(): void
    {
        if ($this->fp) { @flock($this->fp, LOCK_UN); @fclose($this->fp); }
    }

    public function __destruct() { $this->release(); }
}
