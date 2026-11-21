<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Serialization;

use InvalidArgumentException;
use SplObjectStorage;
use UnexpectedValueException;

/**
 * PHP serialize()/unserialize().
 *
 * By default no objects are allowed: only scalars and arrays can be cached,
 * which makes the serializer safe even if an attacker can write to a cache
 * backend. To cache objects, list their classes in $allowedClasses (or pass
 * true for any class); MultiTierCache then requires a signing key, because
 * unserializing attacker-controlled objects can execute code.
 *
 * Objects of classes that are not allowed are rejected when writing (the
 * write fails with an exception) and treated as invalid when reading (a
 * cache miss), instead of silently turning into __PHP_Incomplete_Class.
 */
final readonly class NativeSerializer implements SerializerInterface
{
    private const string SERIALIZED_FALSE = 'b:0;';

    /** @var bool|list<class-string> */
    private bool|array $allowedClasses;
    /** @var array<string, true> lower-cased allowed class names */
    private array $allowedLookup;
    private int $maxDepth;

    /**
     * @param bool|list<class-string> $allowedClasses false = no objects (default),
     *                                                a list of classes, or true = any class
     * @param int $maxDepth Maximum nesting depth (0 = PHP default)
     */
    public function __construct(bool|array $allowedClasses = false, int $maxDepth = 0)
    {
        if (is_array($allowedClasses)) {
            $allowedClasses = array_values($allowedClasses);
            $lookup = [];
            foreach ($allowedClasses as $class) {
                $lookup[strtolower(ltrim($class, '\\'))] = true;
            }
            $this->allowedLookup = $lookup;
        } else {
            $this->allowedLookup = [];
        }

        $this->allowedClasses = $allowedClasses === [] ? false : $allowedClasses;
        $this->maxDepth = max(0, $maxDepth);
    }

    public function serialize(mixed $value): string
    {
        if ($this->allowedClasses !== true) {
            $this->assertAllowed($value, new SplObjectStorage());
        }

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

        if ($this->allowedClasses !== true && $this->containsIncompleteObject($value, new SplObjectStorage())) {
            throw new UnexpectedValueException(
                'Cache payload contains an object of a class that is not in allowedClasses'
            );
        }

        return $value;
    }

    public function name(): string
    {
        return 'php';
    }

    public function allowsObjects(): bool
    {
        return $this->allowedClasses !== false;
    }

    /**
     * @param SplObjectStorage<object, mixed> $seen
     */
    private function assertAllowed(mixed $value, SplObjectStorage $seen): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->assertAllowed($item, $seen);
            }
            return;
        }

        if (!is_object($value) || $seen->contains($value)) {
            return;
        }
        $seen->attach($value);

        if (!isset($this->allowedLookup[strtolower($value::class)])) {
            throw new InvalidArgumentException(sprintf(
                'Cannot cache an object of class %s: add it to NativeSerializer allowedClasses',
                $value::class
            ));
        }

        foreach ((array) $value as $property) {
            $this->assertAllowed($property, $seen);
        }
    }

    /**
     * @param SplObjectStorage<object, mixed> $seen
     */
    private function containsIncompleteObject(mixed $value, SplObjectStorage $seen): bool
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->containsIncompleteObject($item, $seen)) {
                    return true;
                }
            }
            return false;
        }

        if (!is_object($value) || $seen->contains($value)) {
            return false;
        }
        if ($value instanceof \__PHP_Incomplete_Class) {
            return true;
        }
        $seen->attach($value);

        foreach ((array) $value as $property) {
            if ($this->containsIncompleteObject($property, $seen)) {
                return true;
            }
        }

        return false;
    }
}
