<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Serialization;

use UnexpectedValueException;

/**
 * PHP serialize()/unserialize().
 *
 * Unserializing data that an attacker can write runs arbitrary code through
 * magic methods (PHP object injection). Restrict $allowedClasses to the
 * classes you really cache, and/or configure a signing key on MultiTierCache
 * so tampered entries are rejected before they are unserialized.
 */
final class NativeSerializer implements SerializerInterface
{
    private const SERIALIZED_FALSE = 'b:0;';

    /** @var bool|list<class-string> */
    private bool|array $allowedClasses;
    private int $maxDepth;

    /**
     * @param bool|list<class-string> $allowedClasses true = any class, false = no objects, or a list of classes
     * @param int $maxDepth Maximum nesting depth (0 = PHP default)
     */
    public function __construct(bool|array $allowedClasses = true, int $maxDepth = 0)
    {
        $this->allowedClasses = is_array($allowedClasses) ? array_values($allowedClasses) : $allowedClasses;
        $this->maxDepth = max(0, $maxDepth);
    }

    public function serialize(mixed $value): string
    {
        return serialize($value);
    }

    public function deserialize(string $payload): mixed
    {
        if ($payload === self::SERIALIZED_FALSE) {
            return false;
        }

        $options = ['allowed_classes' => $this->allowedClasses];
        if ($this->maxDepth > 0) {
            $options['max_depth'] = $this->maxDepth;
        }

        $value = @unserialize($payload, $options);
        if ($value === false) {
            throw new UnexpectedValueException('Unable to unserialize cache payload');
        }

        return $value;
    }

    public function name(): string
    {
        return 'php';
    }
}
