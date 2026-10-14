<?php

namespace App\Jobs;

use App\Messaging\WhatsAppClient;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * One WhatsApp update, sent by the queue worker instead of inside the shopper's page request,
 * so a slow Meta API never slows down or times out checkout. Failures are logged by the client,
 * not retried: a late duplicate message is worse than a missed one (the bell still has it).
 */
class SendWhatsApp implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 20;

    public function __construct(
        public readonly string $to,
        public readonly string $firstName,
        public readonly string $text,
        public readonly string $url,
    ) {}

    public function handle(WhatsAppClient $whatsapp): void
    {
        $whatsapp->send($this->to, $this->firstName, $this->text, $this->url);
    }
}
