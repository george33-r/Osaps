<?php

namespace App\Messaging;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * WhatsApp Cloud API (Meta). Business-initiated messages must use a template approved in your
 * WhatsApp Manager; we use one general template with three text variables:
 *   {{1}} the person's first name, {{2}} what happened, {{3}} a link to open it on OSAPS.
 * Off until WHATSAPP_TOKEN and WHATSAPP_PHONE_NUMBER_ID are set.
 */
class WhatsAppClient
{
    public function __construct(
        private readonly ?string $token,
        private readonly ?string $phoneNumberId,
        private readonly string $template,
        private readonly string $language,
        private readonly string $graphVersion = 'v21.0',
    ) {}

    public function enabled(): bool
    {
        return (string) $this->token !== '' && (string) $this->phoneNumberId !== '';
    }

    /** Nigerian 0801… or +234 801… → 2348012345678. Returns null if it does not look like a phone number. */
    public static function normalise(?string $phone): ?string
    {
        $d = preg_replace('/\D/', '', (string) $phone) ?? '';
        if ($d === '') {
            return null;
        }
        if (str_starts_with($d, '0') && strlen($d) === 11) {
            $d = '234'.substr($d, 1);
        }

        return strlen($d) >= 10 && strlen($d) <= 15 ? $d : null;
    }

    public function send(string $to, string $firstName, string $message, string $url): bool
    {
        if (! $this->enabled()) {
            return false;
        }
        try {
            $res = Http::withToken((string) $this->token)->acceptJson()->timeout(6)
                ->post("https://graph.facebook.com/{$this->graphVersion}/{$this->phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => $to,
                    'type' => 'template',
                    'template' => [
                        'name' => $this->template,
                        'language' => ['code' => $this->language],
                        'components' => [[
                            'type' => 'body',
                            'parameters' => [
                                ['type' => 'text', 'text' => mb_substr($firstName, 0, 40)],
                                ['type' => 'text', 'text' => mb_substr($message, 0, 300)],
                                ['type' => 'text', 'text' => $url],
                            ],
                        ]],
                    ],
                ]);
            if (! $res->successful()) {
                Log::warning('WhatsApp send failed', ['status' => $res->status(), 'error' => $res->json('error.message')]);
            }

            return $res->successful();
        } catch (\Throwable $e) {
            Log::warning('WhatsApp unreachable', ['error' => $e->getMessage()]);

            return false;
        }
    }
}
