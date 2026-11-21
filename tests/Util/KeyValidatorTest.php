<?php

declare(strict_types=1);

namespace Iprodev\EasyCache\Tests\Util;

use Iprodev\EasyCache\Exceptions\InvalidArgument;
use Iprodev\EasyCache\Util\KeyValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\InvalidArgumentException;

class KeyValidatorTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function validKeys(): iterable
    {
        yield 'simple' => ['simple_key'];
        yield 'dots' => ['key.with.dots'];
        yield 'digits' => ['key123'];
        yield 'mixed case' => ['UPPERCASE_key'];
        yield 'single char' => ['a'];
        yield 'dash' => ['user-123'];
        yield 'uuid' => ['3f2a9c1e-77b2-4f3b-9a51-1c2d3e4f5a6b'];
        yield 'symbols' => ['a!#$%&*+=?[]^|~,;<>\'"`'];
        yield 'max length' => [str_repeat('k', KeyValidator::MAX_LENGTH)];
    }

    #[DataProvider('validKeys')]
    public function testValidKeys(string $key): void
    {
        KeyValidator::assert($key);
        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'too long' => [str_repeat('k', KeyValidator::MAX_LENGTH + 1)];
        foreach (str_split(KeyValidator::RESERVED_CHARACTERS) as $char) {
            yield "reserved {$char}" => ["in{$char}valid"];
        }
        yield 'space' => ['invalid key'];
        yield 'trailing newline' => ["abc\n"];
        yield 'leading newline' => ["\nabc"];
        yield 'tab' => ["a\tb"];
        yield 'nul byte' => ["a\0b"];
        yield 'del' => ["a\x7fb"];
        yield 'utf-8' => ['سلام'];
    }

    #[DataProvider('invalidKeys')]
    public function testInvalidKeys(string $key): void
    {
        $this->expectException(InvalidArgument::class);
        KeyValidator::assert($key);
    }

    public function testExceptionImplementsPsr16Interface(): void
    {
        try {
            KeyValidator::assert('bad:key');
            $this->fail('expected exception');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('bad:key', $e->getMessage());
        }
    }

    public function testErrorMessageEscapesControlCharacters(): void
    {
        try {
            KeyValidator::assert("bad\nkey");
            $this->fail('expected exception');
        } catch (InvalidArgument $e) {
            $this->assertStringNotContainsString("\n", $e->getMessage());
        }
    }

    public function testNormalize(): void
    {
        $this->assertSame('123', KeyValidator::normalize(123));
        $this->assertSame('abc', KeyValidator::normalize('abc'));

        $this->expectException(InvalidArgument::class);
        KeyValidator::normalize(1.5);
    }
}
