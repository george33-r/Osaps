<?php

namespace App\Services;

use App\Domain\Support\DomainRuleViolation;
use App\Models\AdminAction;
use App\Models\Moment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Moments: short photos and videos in circles on the home page, like stories on social apps.
 * Anyone signed in can post. Each post is checked by an admin before it shows, and disappears after 24 hours.
 */
class MomentService
{
    public const MAX_WAITING = 3;

    public const MAX_PER_DAY = 10;

    /** @return array{0:string,1:string,2:int} media type, file extension, size limit in KB */
    private function kindOf(string $mime): array
    {
        return match ($mime) {
            'image/jpeg' => ['photo', 'jpg', 8192],
            'image/png' => ['photo', 'png', 8192],
            'image/webp' => ['photo', 'webp', 8192],
            'video/mp4' => ['video', 'mp4', 30720],
            'video/webm' => ['video', 'webm', 30720],
            'video/quicktime' => ['video', 'mov', 30720],
            default => throw new DomainRuleViolation('Upload a JPG, PNG, WebP photo or an MP4, WebM or MOV video.', 'media_type'),
        };
    }

    public function post(User $user, UploadedFile $file, ?string $caption): Moment
    {
        [$type, $ext, $maxKb] = $this->kindOf((string) $file->getMimeType());
        if ($file->getSize() > $maxKb * 1024) {
            throw new DomainRuleViolation('That file is too large for a moment.', 'too_large');
        }

        $waiting = Moment::query()->where('author_id', $user->id)->where('status', Moment::PENDING)->count();
        if ($waiting >= self::MAX_WAITING) {
            throw new DomainRuleViolation('You have '.$waiting.' moments waiting for a check. Wait for a decision before posting more.', 'too_many_pending');
        }
        $today = Moment::query()->where('author_id', $user->id)->where('created_at', '>=', now()->startOfDay())->count();
        if ($today >= self::MAX_PER_DAY) {
            throw new DomainRuleViolation('You have reached today\'s limit of '.self::MAX_PER_DAY.' moments. Try again tomorrow.', 'daily_limit');
        }

        $dir = 'uploads/moments/'.$user->id;
        $name = Str::lower(Str::random(24)).'.'.$ext;
        $file->move(public_path($dir), $name);

        return Moment::query()->create([
            'author_id' => $user->id,
            'tailor_profile_id' => $user->tailorProfile?->id,
            'media_type' => $type,
            'media_path' => $dir.'/'.$name,
            'mime' => (string) $file->getMimeType(),
            'caption' => $caption !== null && trim($caption) !== '' ? mb_substr(trim($caption), 0, 140) : null,
            'status' => Moment::PENDING,
        ]);
    }

    public function decide(User $admin, Moment $m, string $to, ?string $note): Moment
    {
        if ($to === Moment::HIDDEN && mb_strlen(trim((string) $note)) < 5) {
            throw new DomainRuleViolation('Say why the moment is hidden (at least 5 characters).', 'note_required');
        }
        $m->forceFill([
            'status' => $to,
            'published_at' => $to === Moment::PUBLISHED ? now() : $m->published_at,
            'hidden_reason' => $to === Moment::HIDDEN ? mb_substr(trim((string) $note), 0, 300) : null,
            'decided_by' => $admin->id,
        ])->save();
        AdminAction::record($admin, 'moment_'.$to, $m, $note);

        return $m;
    }

    public function delete(User $actor, Moment $m): void
    {
        if ($actor->role !== 'admin' && $m->author_id !== $actor->id) {
            throw new AuthorizationException('You can only delete your own moments.');
        }
        if (is_file(public_path($m->media_path))) {
            @unlink(public_path($m->media_path));
        }
        $m->delete();
    }
}
