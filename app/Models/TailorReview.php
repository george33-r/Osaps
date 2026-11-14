<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class TailorReview extends Model
{
    public const PUBLISHED = 'published';

    public const HIDDEN = 'hidden';

    protected $fillable = ['order_id', 'customer_id', 'tailor_id', 'stars', 'quality', 'communication', 'delivery_time', 'value_for_money', 'body', 'status', 'hidden_reason', 'submitted_at', 'corrected_at', 'correction_count'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'corrected_at' => 'datetime', 'correction_count' => 'integer'];
    }

    public function scopePublished(Builder $q): Builder
    {
        return $q->where('status', self::PUBLISHED);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function tailor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tailor_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(TailorReviewRevision::class, 'review_id')->orderBy('version');
    }

    public function response(): HasOne
    {
        return $this->hasOne(TailorReviewResponse::class, 'review_id');
    }

    public function photos(): HasMany
    {
        return $this->hasMany(TailorReviewPhoto::class, 'review_id')->orderBy('id');
    }

    public function reports(): HasMany
    {
        return $this->hasMany(TailorReviewReport::class, 'review_id');
    }

    public function appeals(): HasMany
    {
        return $this->hasMany(TailorReviewAppeal::class, 'review_id');
    }
}
