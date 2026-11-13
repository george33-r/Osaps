<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A message an admin sends to selected tailors. Queued, so the request returns at once and cron sends it.
 */
class AdminBroadcast extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $subjectLine, public readonly string $messageBody) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(text: 'emails.admin-broadcast');
    }
}
