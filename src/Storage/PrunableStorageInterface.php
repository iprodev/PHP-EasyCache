<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Storage;

/**
 * Storages whose expired entries are not removed automatically by the
 * backend (files, SQL tables) implement this interface.
 */
interface PrunableStorageInterface extends StorageInterface
{
    /**
     * Remove expired entries. Returns the number of removed entries.
     */
    public function prune(): int;
}
