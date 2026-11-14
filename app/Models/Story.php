<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Story extends Model
{
    public const PENDING = 'pending';

    public const PUBLISHED = 'published';

    public const HIDDEN = 'hidden';

    public const KINDS = ['story', 'tip', 'spotlight'];

    protected $fillable = ['author_id', 'tailor_profile_id', 'kind', 'title', 'body', 'link_url', 'photo_path', 'status', 'hidden_reason', 'published_at', 'decided_by'];

    /** The stored file path is never sent to clients; the public URL is built from it. */
    protected $hidden = ['photo_path'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function scopePublished(Builder $q): Builder
    {
        return $q->where('status', self::PUBLISHED);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function tailorProfile(): BelongsTo
    {
        return $this->belongsTo(TailorProfile::class);
    }
}
