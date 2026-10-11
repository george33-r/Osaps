<?php

namespace App\Ai\Providers;

use App\Ai\AiException;
use App\Ai\AiProvider;
use App\Ai\AiResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Claude via the Anthropic Messages API (POST /v1/messages).
 * Key, model and version come from config/ai.php; nothing is exposed to the browser.
 */
final class AnthropicProvider implements AiProvider
{
    public function __construct(private readonly array $config, private readonly int $timeout) {}

    public function name(): string
    {
        return 'anthropic';
    }

    public function available(): bool
    {
        return ! empty($this->config['api_key']);
    }

    public function complete(string $system, array $content, int $maxTokens, bool $vision = false): AiResponse
    {
        if (! $this->available()) {
            throw AiException::unavailable();
        }

        $model = $vision ? $this->config['vision_model'] : $this->config['model'];
        $blocks = array_map(fn (array $b) => $b['type'] === 'image'
            ? ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $b['media_type'], 'data' => $b['data']]]
            : ['type' => 'text', 'text' => $b['text']], $content);

        try {
            $response = Http::baseUrl(rtrim($this->config['base_url'], '/'))
                ->withHeaders([
                    'x-api-key' => $this->config['api_key'],
                    'anthropic-version' => $this->config['version'],
                ])
                ->acceptJson()
                ->connectTimeout(5)
                ->timeout($this->timeout)
                // One quick retry only when the connection could not be made. Never after a timeout
                // (cURL error 28): a second full wait would outlast the PHP time limit on shared hosting.
                ->retry(2, 300, fn ($e) => $e instanceof ConnectionException && ! str_contains($e->getMessage(), 'cURL error 28'), throw: false)
                ->post('/v1/messages', [
                    'model' => $model,
                    'max_tokens' => $maxTokens,
                    'system' => $system,
                    'messages' => [['role' => 'user', 'content' => $blocks]],
                ]);
        } catch (ConnectionException $e) {
            throw new AiException('timeout', 'The AI service did not respond in time.');
        }

        if (! $response->successful()) {
            throw new AiException('provider_error', 'The AI service returned an error ('.$response->status().').');
        }

        $text = collect($response->json('content', []))
            ->where('type', 'text')
            ->pluck('text')
            ->implode("\n");

        if ($response->json('stop_reason') === 'refusal' || trim($text) === '') {
            throw new AiException('invalid_output', 'The AI service returned no usable answer.');
        }

        return new AiResponse(
            $text,
            (int) $response->json('usage.input_tokens', 0),
            (int) $response->json('usage.output_tokens', 0),
            (string) $response->json('model', $model),
        );
    }
}
