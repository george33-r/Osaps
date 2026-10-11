<?php

namespace App\Ai\Providers;

use App\Ai\AiException;
use App\Ai\AiProvider;
use App\Ai\AiResponse;

/**
 * Test double: returns queued replies in order and records what it was sent.
 * Bind it in tests with $this->app->instance(AiProvider::class, $fake). Never used in production.
 */
final class FakeProvider implements AiProvider
{
    /** @var list<string|AiException> */
    private array $replies;

    /** @var list<array{system:string, content:array, vision:bool}> */
    public array $sent = [];

    public function __construct(string|AiException ...$replies)
    {
        $this->replies = $replies;
    }

    public function name(): string
    {
        return 'fake';
    }

    public function available(): bool
    {
        return true;
    }

    public function complete(string $system, array $content, int $maxTokens, bool $vision = false): AiResponse
    {
        $this->sent[] = ['system' => $system, 'content' => $content, 'vision' => $vision];
        $next = array_shift($this->replies) ?? throw new AiException('provider_error', 'No fake reply queued.');
        if ($next instanceof AiException) {
            throw $next;
        }

        return new AiResponse($next, 10, 10, 'fake-model');
    }
}
