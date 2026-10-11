<?php

namespace App\Ai\Providers;

use App\Ai\AiException;
use App\Ai\AiProvider;
use App\Ai\AiResponse;

/** Used when no provider is configured. The app keeps working in rule-based mode. */
final class UnavailableProvider implements AiProvider
{
    public function name(): string
    {
        return 'none';
    }

    public function available(): bool
    {
        return false;
    }

    public function complete(string $system, array $content, int $maxTokens, bool $vision = false): AiResponse
    {
        throw AiException::unavailable();
    }
}
