<?php

namespace App\Services;

use App\Domain\Support\DomainRuleViolation;
use App\Models\AdminAction;
use App\Models\Story;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Home page stories.
 * - A tailor's story waits for an admin to publish it. Editing a published story sends it back to waiting.
 * - An admin's story is published at once. Admins can also feature a tailor (spotlight) and attach a tailor profile.
 * - Links must stay on OSAPS (a path starting with a single slash).
 */
class StoryService
{
    public const MAX_PENDING_PER_TAILOR = 3;

    private const PHOTO_EXT = ['jpeg' => 'jpg', 'jpg' => 'jpg', 'png' => 'png', 'webp' => 'webp'];

    public function __construct(private readonly Notifier $notifier) {}

    /** @param array<string,mixed> $data */
    public function create(User $actor, array $data, ?UploadedFile $photo = null): Story
    {
        return DB::transaction(function () use ($actor, $data, $photo) {
            if ($actor->role === 'admin') {
                $story = Story::query()->create($this->fields($data) + [
                    'author_id' => $actor->id,
                    'tailor_profile_id' => $data['tailor_profile_id'] ?? null,
                    'kind' => $data['kind'] ?? 'story',
                    'status' => Story::PUBLISHED,
                    'published_at' => now(),
                    'decided_by' => $actor->id,
                ]);
                AdminAction::record($actor, 'story_published', $story, null, ['kind' => $story->kind]);
            } else {
                $profile = $actor->tailorProfile ?? throw new DomainRuleViolation('Set up your tailor profile before writing a story.', 'profile_missing');
                $waiting = Story::query()->where('author_id', $actor->id)->where('status', Story::PENDING)->count();
                if ($waiting >= self::MAX_PENDING_PER_TAILOR) {
                    throw new DomainRuleViolation('You have '.$waiting.' stories waiting for an OSAPS check. Wait for a decision before adding more.', 'too_many_pending');
                }
                $story = Story::query()->create($this->fields($data) + [
                    'author_id' => $actor->id,
                    'tailor_profile_id' => $profile->id,
                    'kind' => 'story',
                    'status' => Story::PENDING,
                ]);
            }
            if ($photo !== null) {
                $this->attachPhoto($story, $photo);
            }

            return $story->refresh();
        });
    }

    /** @param array<string,mixed> $data */
    public function update(User $actor, Story $story, array $data, ?UploadedFile $photo = null): Story
    {
        $isAdmin = $actor->role === 'admin';
        if (! $isAdmin && $story->author_id !== $actor->id) {
            throw new AuthorizationException('You can only edit your own stories.');
        }

        return DB::transaction(function () use ($actor, $story, $data, $photo, $isAdmin) {
            $story = Story::query()->lockForUpdate()->findOrFail($story->id);
            $changes = $this->fields($data);
            if ($isAdmin) {
                $changes['kind'] = $data['kind'] ?? $story->kind;
                $changes['tailor_profile_id'] = array_key_exists('tailor_profile_id', $data) ? $data['tailor_profile_id'] : $story->tailor_profile_id;
            } else {
                // Any change from a tailor goes back to an OSAPS check.
                $changes['status'] = Story::PENDING;
                $changes['hidden_reason'] = null;
                $changes['published_at'] = null;
            }
            $story->forceFill($changes)->save();
            if ($photo !== null) {
                $this->attachPhoto($story, $photo);
            }
            if ($isAdmin) {
                AdminAction::record($actor, 'story_edited', $story);
            }

            return $story->refresh();
        });
    }

    public function setStatus(User $admin, Story $story, string $to, ?string $note): Story
    {
        if (! in_array($to, [Story::PUBLISHED, Story::HIDDEN], true)) {
            throw new DomainRuleViolation('Status must be published or hidden.', 'bad_status');
        }
        if ($to === Story::HIDDEN && mb_strlen(trim((string) $note)) < 5) {
            throw new DomainRuleViolation('Say why the story is hidden (at least 5 characters).', 'note_required');
        }

        return DB::transaction(function () use ($admin, $story, $to, $note) {
            $story = Story::query()->lockForUpdate()->findOrFail($story->id);
            $story->forceFill([
                'status' => $to,
                'hidden_reason' => $to === Story::HIDDEN ? mb_substr(trim((string) $note), 0, 300) : null,
                'published_at' => $to === Story::PUBLISHED ? ($story->published_at ?? now()) : $story->published_at,
                'decided_by' => $admin->id,
            ])->save();
            AdminAction::record($admin, 'story_'.$to, $story, $note);

            if ($story->author?->role === 'tailor' && $story->author_id !== $admin->id) {
                $text = $to === Story::PUBLISHED
                    ? 'Your story is live on the OSAPS home page.'
                    : 'Your story was hidden: '.trim((string) $note);
                $this->notifier->notify($story->author_id, 'story_status', 'Story update', $text, '/tailor/inbox');
            }

            return $story;
        });
    }

    public function delete(User $actor, Story $story): void
    {
        if ($actor->role !== 'admin' && $story->author_id !== $actor->id) {
            throw new AuthorizationException('You can only delete your own stories.');
        }
        $this->removePhoto($story);
        $story->delete();
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private function fields(array $data): array
    {
        return [
            'title' => mb_substr(trim((string) $data['title']), 0, 80),
            'body' => mb_substr(trim((string) $data['body']), 0, 600),
            'link_url' => isset($data['link_url']) && trim((string) $data['link_url']) !== '' ? trim((string) $data['link_url']) : null,
        ];
    }

    /** Photos sit in the public uploads folder, like tailor profile photos. */
    private function attachPhoto(Story $story, UploadedFile $file): void
    {
        $ext = self::PHOTO_EXT[$file->guessExtension()] ?? null;
        if ($ext === null) {
            throw new DomainRuleViolation('Upload a JPG, PNG or WebP photo.', 'photo_type');
        }
        $this->removePhoto($story);
        $dir = 'uploads/stories/'.$story->id;
        $name = Str::lower(Str::random(24)).'.'.$ext;
        $file->move(public_path($dir), $name);
        $story->forceFill(['photo_path' => $dir.'/'.$name])->save();
    }

    private function removePhoto(Story $story): void
    {
        if ($story->photo_path && is_file(public_path($story->photo_path))) {
            @unlink(public_path($story->photo_path));
        }
    }
}
