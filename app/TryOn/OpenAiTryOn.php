<?php

namespace App\TryOn;

use App\Domain\Support\DomainRuleViolation;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenAI image editing (POST /v1/images/edits). The person's photo, and the vendor's fabric photo when
 * there is one, are sent with a prompt; the result comes back as base64 and is returned straight to
 * the browser. Nothing is written to disk. Not exercised in the build environment: test it with your
 * own key before switching it on for customers.
 */
class OpenAiTryOn implements TryOnProvider
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly int $timeout,
    ) {}

    public function available(): bool
    {
        return (string) $this->apiKey !== '';
    }

    public function render(string $photo, string $photoMime, ?string $fabricPhoto, ?string $fabricMime, string $prompt): string
    {
        $req = Http::withToken((string) $this->apiKey)->timeout($this->timeout)->asMultipart()
            ->attach('image[]', $photo, 'person.'.$this->ext($photoMime), ['Content-Type' => $photoMime]);
        if ($fabricPhoto !== null && $fabricMime !== null) {
            $req = $req->attach('image[]', $fabricPhoto, 'fabric.'.$this->ext($fabricMime), ['Content-Type' => $fabricMime]);
        }

        try {
            $res = $req->post('https://api.openai.com/v1/images/edits', [
                'model' => $this->model,
                'prompt' => $prompt,
                'size' => '1024x1536',
                'n' => 1,
            ]);
        } catch (\Throwable $e) {
            Log::warning('Try-on provider unreachable', ['error' => $e->getMessage()]);
            throw new DomainRuleViolation('The try-on service did not answer. Please try again.', 'try_on_unreachable');
        }

        $b64 = $res->json('data.0.b64_json');
        if (! $res->successful() || ! is_string($b64)) {
            Log::warning('Try-on failed', ['status' => $res->status(), 'error' => $res->json('error.message')]);
            throw new DomainRuleViolation($res->status() === 400
                ? 'This photo could not be used. Try a clear, well-lit photo of just you, standing.'
                : 'Try-on could not make a picture right now. Please try again.', 'try_on_failed');
        }
        $bytes = base64_decode($b64, true);
        if ($bytes === false) {
            throw new DomainRuleViolation('Try-on returned an unreadable picture.', 'try_on_failed');
        }

        return $bytes;
    }

    private function ext(string $mime): string
    {
        return match ($mime) { 'image/png' => 'png', 'image/webp' => 'webp', default => 'jpg' };
    }
}
