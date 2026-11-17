<?php

namespace App\Services;

use App\Domain\Support\DomainRuleViolation;
use App\Models\AdminAction;
use App\Models\Moment;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Moments: short photos and videos in circles on the home page, like stories on social apps.
 * Anyone signed in can post (customers, tailors, vendors). A post shows straight away for everyone, disappears after
 * 24 hours, and an admin can hide or remove any post that breaks the rules.
 */
class MomentService
{
    public const MAX_PER_DAY = 10;

    /** Videos up to 1 GB. The server's own PHP upload limit must be at least this (see the hosting settings). */
    public const MAX_VIDEO_KB = 1048576;

    /** @return array{0:string,1:string,2:int} media type, file extension, size limit in KB */
    private function kindOf(string $mime): array
    {
        return match ($mime) {
            'image/jpeg' => ['photo', 'jpg', 8192],
            'image/png' => ['photo', 'png', 8192],
            'image/webp' => ['photo', 'webp', 8192],
            'video/mp4' => ['video', 'mp4', self::MAX_VIDEO_KB],
            'video/webm' => ['video', 'webm', self::MAX_VIDEO_KB],
            'video/quicktime' => ['video', 'mov', self::MAX_VIDEO_KB],
            default => throw new DomainRuleViolation('Upload a JPG, PNG, WebP photo or an MP4, WebM or MOV video.', 'media_type'),
        };
    }

    public function post(User $user, UploadedFile $file, ?string $caption): Moment
    {
        // Read the type now: once the file is moved, PHP can no longer inspect it.
        $mime = (string) $file->getMimeType();
        [$type, $ext, $maxKb] = $this->kindOf($mime);
        if ($file->getSize() > $maxKb * 1024) {
            throw new DomainRuleViolation('That file is too large for a moment.', 'too_large');
        }

        $today = Moment::query()->where('author_id', $user->id)->where('created_at', '>=', now()->startOfDay())->count();
        if ($today >= self::MAX_PER_DAY) {
            throw new DomainRuleViolation('You have reached today\'s limit of '.self::MAX_PER_DAY.' moments. Try again tomorrow.', 'daily_limit');
        }

        $dir = 'uploads/moments/'.$user->id;
        $name = Str::lower(Str::random(24)).'.'.$ext;
        File::ensureDirectoryExists(public_path($dir));
        $file->move(public_path($dir), $name);

        return Moment::query()->create([
            'author_id' => $user->id,
            'tailor_profile_id' => $user->tailorProfile?->id,
            'media_type' => $type,
            'media_path' => $dir.'/'.$name,
            'mime' => $mime,
            'caption' => $caption !== null && trim($caption) !== '' ? mb_substr(trim($caption), 0, 140) : null,
            'status' => Moment::PUBLISHED,
            'published_at' => now(),
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
