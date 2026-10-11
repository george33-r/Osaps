<?php

namespace App\Models;

use App\Domain\Negotiation\JobRequestStatus;
use App\Domain\Negotiation\OfferStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class JobRequest extends Model
{
    use HasFactory, HasUlids;

    protected $fillable = [
        'title', 'garment_category', 'occasion', 'description', 'style_tags', 'mode', 'budget_min_minor', 'budget_max_minor',
        'fabric_status', 'fabric_id', 'city', 'needed_by', 'fitting_required',
    ];

    /** Public ULID in URLs so sequential IDs are never exposed or guessable. */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'style_tags' => 'array',
            'needed_by' => 'date',
            'fitting_required' => 'boolean',
            'offers_close_at' => 'datetime',
            'budget_min_minor' => 'integer',
            'budget_max_minor' => 'integer',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function fabric(): BelongsTo
    {
        return $this->belongsTo(Fabric::class);
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function liveOffers(): HasMany
    {
        return $this->offers()->whereIn('status', OfferStatus::LIVE)->where('expires_at', '>', now());
    }

    public function order(): HasOne
    {
        return $this->hasOne(Order::class);
    }

    public function isOpen(): bool
    {
        return $this->status === JobRequestStatus::OPEN && $this->offers_close_at->isFuture();
    }

    /** Requests a tailor can see in their inbox. */
    public function scopeOpenForTailors(Builder $q): Builder
    {
        return $q->where('status', JobRequestStatus::OPEN)->where('offers_close_at', '>', now());
    }
}
