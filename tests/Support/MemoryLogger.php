<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Support;

use Psr\Log\AbstractLogger;

final class MemoryLogger extends AbstractLogger
{
    /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
    public array $records = [];

    /**
     * Untyped $message keeps this compatible with psr/log 1.x, 2.x and 3.x.
     *
     * @param mixed                $level
     * @param string|\Stringable   $message
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
    {
        $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
    }

    public function hasMessageContaining(string $needle): bool
    {
        foreach ($this->records as $record) {
            if (str_contains($record['message'], $needle)) {
                return true;
            }
        }
        return false;
    }
}
