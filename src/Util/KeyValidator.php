<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Util;

use Iprodev\EasyCache\Exceptions\InvalidArgument;

final class KeyValidator
{
    public static function assert(string $key): void
    {
        if (
            $key === '' || strlen($key) > 64 ||
            preg_match('/[{}()\/\\@:]/', $key) ||
            !preg_match('/^[A-Za-z0-9_.]+$/', $key)
        ) {
            throw new InvalidArgument("Illegal cache key: '{$key}'");
        }
    }
}
