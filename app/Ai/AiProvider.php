<?php

namespace App\Ai;

/** A model provider. Adapters live in app/Ai/Providers; nothing outside app/Ai talks to a provider directly. */
interface AiProvider
{
    public function name(): string;

    public function available(): bool;

    /**
     * @param  list<array<string,mixed>>  $content  content blocks: ['type'=>'text','text'=>…] or ['type'=>'image','media_type'=>…,'data'=>base64]
     *
     * @throws AiException
     */
    public function complete(string $system, array $content, int $maxTokens, bool $vision = false): AiResponse;
}
