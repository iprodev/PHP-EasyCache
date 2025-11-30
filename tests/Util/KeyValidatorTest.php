<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Util;

use Iprodev\EasyCache\Util\KeyValidator;
use Iprodev\EasyCache\Exceptions\InvalidArgument;
use PHPUnit\Framework\TestCase;

class KeyValidatorTest extends TestCase
{
    public function testValidKeys(): void
    {
        $validKeys = [
            'simple',
            'with_underscore',
            'with.dots',
            'CamelCase',
            'numbers123',
            'a1b2c3',
            'key_1.test',
        ];

        foreach ($validKeys as $key) {
            KeyValidator::assert($key); // Should not throw
            $this->assertTrue(true); // If we got here, validation passed
        }
    }

    public function testEmptyKeyThrowsException(): void
    {
        $this->expectException(InvalidArgument::class);
        KeyValidator::assert('');
    }

    public function testTooLongKeyThrowsException(): void
    {
        $this->expectException(InvalidArgument::class);
        KeyValidator::assert(str_repeat('x', 65));
    }

    public function testKeyWithColonThrowsException(): void
    {
        $this->expectException(InvalidArgument::class);
        KeyValidator::assert('invalid:key');
    }

    public function testKeyWithBracesThrowsException(): void
    {
        $this->expectException(InvalidArgument::class);
        KeyValidator::assert('invalid{key}');
    }

    public function testKeyWithParenthesesThrowsException(): void
    {
        $this->expectException(InvalidArgument::class);
        KeyValidator::assert('invalid(key)');
    }

    public function testKeyWithSlashThrowsException(): void
    {
        $this->expectException(InvalidArgument::class);
        KeyValidator::assert('invalid/key');
    }

    public function testKeyWithBackslashThrowsException(): void
    {
        $this->expectException(InvalidArgument::class);
        KeyValidator::assert('invalid\\key');
    }

    public function testKeyWithAtSignThrowsException(): void
    {
        $this->expectException(InvalidArgument::class);
        KeyValidator::assert('invalid@key');
    }

    public function testKeyWithSpaceThrowsException(): void
    {
        $this->expectException(InvalidArgument::class);
        KeyValidator::assert('invalid key');
    }

    public function testKeyWithSpecialCharsThrowsException(): void
    {
        $invalidKeys = [
            'key!',
            'key#',
            'key$',
            'key%',
            'key&',
            'key*',
            'key+',
            'key=',
            'key[',
            'key]',
        ];

        foreach ($invalidKeys as $key) {
            try {
                KeyValidator::assert($key);
                $this->fail("Expected InvalidArgument exception for key: {$key}");
            } catch (InvalidArgument $e) {
                $this->assertTrue(true);
            }
        }
    }

    public function testMaxLengthKey(): void
    {
        $key = str_repeat('x', 64);
        KeyValidator::assert($key); // Should not throw
        $this->assertTrue(true);
    }

    public function testSingleCharacterKey(): void
    {
        KeyValidator::assert('a');
        $this->assertTrue(true);
    }
}
