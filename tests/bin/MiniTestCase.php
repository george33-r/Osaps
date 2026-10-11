<?php

declare(strict_types=1);

/**
 * Minimal stand-in for PHPUnit\Framework\TestCase, loaded ONLY when PHPUnit is not installed.
 * Implements just the assertions the domain tests use.
 */

namespace PHPUnit\Framework;

class AssertionFailedError extends \Exception {}

abstract class TestCase
{
    private ?string $expectedException = null;

    public function __construct(private string $name = '') {}

    protected function setUp(): void {}

    public function runBare(): void
    {
        $this->expectedException = null;
        $this->setUp();
        try {
            $this->{$this->name}();
        } catch (AssertionFailedError $e) {
            throw $e;
        } catch (\Throwable $e) {
            if ($this->expectedException !== null && $e instanceof $this->expectedException) {
                return;
            }
            throw $e;
        }
        if ($this->expectedException !== null) {
            throw new AssertionFailedError("Expected exception {$this->expectedException} was not thrown.");
        }
    }

    public function expectException(string $class): void
    {
        $this->expectedException = $class;
    }

    public static function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new AssertionFailedError(($message ? $message.' — ' : '').'expected '.var_export($expected, true).', got '.var_export($actual, true));
        }
    }

    public static function assertTrue(mixed $condition, string $message = ''): void
    {
        self::assertSame(true, $condition, $message);
    }

    public static function assertFalse(mixed $condition, string $message = ''): void
    {
        self::assertSame(false, $condition, $message);
    }

    public static function assertCount(int $expected, \Countable|array $haystack, string $message = ''): void
    {
        self::assertSame($expected, count($haystack), $message);
    }

    public static function assertContains(mixed $needle, iterable $haystack, string $message = ''): void
    {
        foreach ($haystack as $item) {
            if ($item === $needle) {
                return;
            }
        }
        throw new AssertionFailedError(($message ? $message.' — ' : '').var_export($needle, true).' not found.');
    }

    public static function assertNotContains(mixed $needle, iterable $haystack, string $message = ''): void
    {
        foreach ($haystack as $item) {
            if ($item === $needle) {
                throw new AssertionFailedError(($message ? $message.' — ' : '').var_export($needle, true).' unexpectedly found.');
            }
        }
    }

    public static function assertGreaterThan(int|float $expected, int|float $actual, string $message = ''): void
    {
        if (! ($actual > $expected)) {
            throw new AssertionFailedError(($message ? $message.' — ' : '')."expected > {$expected}, got {$actual}");
        }
    }

    public static function assertLessThan(int|float $expected, int|float $actual, string $message = ''): void
    {
        if (! ($actual < $expected)) {
            throw new AssertionFailedError(($message ? $message.' — ' : '')."expected < {$expected}, got {$actual}");
        }
    }
}
