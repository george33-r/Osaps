<?php

namespace App\Ai;

use RuntimeException;

/**
 * Any AI failure. Features catch it and fall back to their rule-based mode.
 * kind: unavailable | timeout | provider_error | invalid_output | rate_limited
 */
class AiException extends RuntimeException
{
    public function __construct(public readonly string $kind, string $message = '')
    {
        parent::__construct($message ?: $kind);
    }

    public static function unavailable(): self
    {
        return new self('unavailable', 'AI assistance is not available right now.');
    }
}
