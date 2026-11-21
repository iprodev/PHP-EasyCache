<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Support;

use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Shared PSR-3 logging helpers for storage backends.
 *
 * Backends never write to error_log() directly: they report through an
 * optional PSR-3 logger. MultiTierCache hands its own logger to every tier
 * that has none, so a single logger configured on the cache covers all tiers.
 *
 * @internal
 */
trait LogsErrors
{
    private ?LoggerInterface $logger = null;

    public function setLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function hasLogger(): bool
    {
        return $this->logger !== null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logWarning(string $message, array $context = [], ?Throwable $exception = null): void
    {
        if ($this->logger === null) {
            return;
        }
        if ($exception !== null) {
            $context['exception'] = $exception;
            $context['error'] = $exception->getMessage();
        }
        $this->logger->warning($message, $context);
    }
}
