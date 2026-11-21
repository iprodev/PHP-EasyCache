<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Util;

use Iprodev\EasyCache\Exceptions\InvalidArgument;

/**
 * Validates cache keys.
 *
 * PSR-16 requires support for at least `A-Z a-z 0-9 _ .` up to 64 characters
 * and forbids the reserved characters `{}()/\@:`. EasyCache accepts every
 * printable ASCII character except whitespace and the reserved characters,
 * up to 255 bytes, so keys such as UUIDs (`user-3f2a...`) work.
 */
final class KeyValidator
{
    public const int MAX_LENGTH = 255;
    public const string RESERVED_CHARACTERS = '{}()/\\@:';

    /**
     * @throws InvalidArgument
     */
    public static function assert(string $key): void
    {
        if ($key === '') {
            throw new InvalidArgument('Cache key must not be empty');
        }

        if (strlen($key) > self::MAX_LENGTH) {
            throw new InvalidArgument(sprintf(
                'Cache key "%s..." is too long (%d bytes, max %d)',
                self::printable(substr($key, 0, 32)),
                strlen($key),
                self::MAX_LENGTH
            ));
        }

        // Anything outside printable ASCII (0x21-0x7E) or a reserved character.
        if (preg_match('/[^\x21-\x7E]|[{}()\/\\\\@:]/', $key) === 1) {
            throw new InvalidArgument(sprintf(
                'Illegal cache key "%s": keys may contain printable ASCII characters except whitespace and %s',
                self::printable($key),
                self::RESERVED_CHARACTERS
            ));
        }
    }

    /**
     * Validate a key coming from an iterable passed to the *Multiple() methods
     * and return it as a string.
     *
     * @throws InvalidArgument
     */
    public static function normalize(mixed $key): string
    {
        if (is_int($key)) {
            // PHP turns numeric string array keys into integers.
            $key = (string) $key;
        }

        if (!is_string($key)) {
            throw new InvalidArgument(sprintf('Cache keys must be strings, %s given', get_debug_type($key)));
        }

        self::assert($key);

        return $key;
    }

    private static function printable(string $value): string
    {
        return addcslashes(substr($value, 0, 100), "\0..\37\177..\377\"");
    }
}
