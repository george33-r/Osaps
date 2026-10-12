<?php

namespace App\Services;

use App\Messaging\WhatsAppClient;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\DB;

/**
 * Tells people what happened: always in the site's notification bell, and on WhatsApp too when
 * the person has switched WhatsApp updates on and given a phone number. WhatsApp is sent only
 * after the database change has committed, so nobody is told about something that rolled back.
 */
class Notifier
{
    public function __construct(private readonly WhatsAppClient $whatsapp) {}

    public function notify(User|int|null $user, string $kind, string $title, ?string $body = null, ?string $link = null): void
    {
        if ($user === null) {
            return;
        }
        $user = $user instanceof User ? $user : User::query()->find($user);
        if ($user === null) {
            return;
        }

        UserNotification::query()->create([
            'user_id' => $user->id, 'kind' => $kind,
            'title' => mb_substr($title, 0, 120), 'body' => $body !== null ? mb_substr($body, 0, 300) : null, 'link' => $link,
        ]);

        $to = $user->whatsapp_opt_in ? WhatsAppClient::normalise($user->phone) : null;
        if ($to === null || ! $this->whatsapp->enabled()) {
            return;
        }
        $first = strtok((string) $user->name, ' ') ?: 'there';
        $url = url($link ?? '/');
        $text = $title.($body ? ' '.$body : '');
        DB::afterCommit(fn () => $this->whatsapp->send($to, $first, $text, $url));
    }
}
