<?php

namespace App\Services;

use App\Domain\Support\DomainRuleViolation;
use App\Mail\AdminBroadcast;
use App\Models\AdminAction;
use App\Models\Announcement;
use App\Models\Story;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Email announcements. Only people who turned on email updates receive them, each message carries a
 * one-click unsubscribe link, and a second announcement cannot go out within 10 minutes of the last.
 */
class AnnouncementService
{
    public const GAP_MINUTES = 10;

    public function audience(string $audience): Builder
    {
        $roles = match ($audience) {
            'tailors' => ['tailor'],
            'customers' => ['customer'],
            'everyone' => ['tailor', 'customer'],
            default => throw new DomainRuleViolation('Choose who should receive it.', 'bad_audience'),
        };

        return User::query()->whereIn('role', $roles)->where('email_updates_opt_in', true)
            ->whereNotNull('email')->where('email', '!=', '');
    }

    public function send(User $admin, string $audience, string $subject, string $body, ?Story $story = null): Announcement
    {
        if (Announcement::query()->where('sent_at', '>=', now()->subMinutes(self::GAP_MINUTES))->exists()) {
            throw new DomainRuleViolation('An announcement went out in the last '.self::GAP_MINUTES.' minutes. Wait a little before sending another.', 'too_soon');
        }

        $message = trim($body);
        if ($story !== null) {
            $message .= "\n\n".$story->title."\n".$story->body."\n\nSee it on OSAPS: ".url('/');
        }

        $announcement = Announcement::query()->create([
            'created_by' => $admin->id, 'audience' => $audience, 'subject' => trim($subject), 'body' => trim($body),
            'story_id' => $story?->id, 'recipients' => 0, 'sent_at' => now(),
        ]);

        $count = 0;
        $this->audience($audience)->orderBy('id')->chunkById(200, function ($users) use ($subject, $message, &$count) {
            foreach ($users as $user) {
                $unsubscribe = URL::signedRoute('v1.email-updates.unsubscribe', ['user' => $user->id]);
                Mail::to($user->email)->queue(new AdminBroadcast(trim($subject), $message, $unsubscribe));
                $count++;
            }
        });
        $announcement->forceFill(['recipients' => $count])->save();
        AdminAction::record($admin, 'announcement_sent', $announcement, null, ['audience' => $audience, 'recipients' => $count]);

        return $announcement;
    }
}
