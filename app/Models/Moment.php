<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A short photo or video shown in a circle on the home page. Visible for 24 hours after it is published. */
class Moment extends Model
{
    public const PENDING = 'pending';

    public const PUBLISHED = 'published';

    public const HIDDEN = 'hidden';

    public const LIFETIME_HOURS = 24;

    protected $fillable = ['author_id', 'tailor_profile_id', 'media_type', 'media_path', 'mime', 'caption', 'status', 'hidden_reason', 'published_at', 'decided_by'];

    /** The stored path is never sent to clients; the public URL is built from it. */
    protected $hidden = ['media_path'];

    protected function casts(): array
    {
        return ['published_at' => 'datetime'];
    }

    public function scopeLive(Builder $q): Builder
    {
        return $q->where('status', self::PUBLISHED)->where('published_at', '>', now()->subHours(self::LIFETIME_HOURS));
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
