<?php

namespace App\Models;

use App\Domain\Negotiation\OfferStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Offer extends Model
{
    use HasFactory;

    // Status and round counters are deliberately not mass-assignable — only NegotiationService changes them.
    protected $fillable = [
        'amount_minor', 'delivery_days', 'includes_fitting', 'alterations_included', 'delivery_fee_minor', 'message',
    ];

    protected function casts(): array
    {
        return [
            'amount_minor' => 'integer',
            'delivery_fee_minor' => 'integer',
            'includes_fitting' => 'boolean',
            'at_customer_budget' => 'boolean',
            'expires_at' => 'datetime',
        ];
    }

    public function jobRequest(): BelongsTo
    {
        return $this->belongsTo(JobRequest::class);
    }

    public function tailor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'tailor_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(OfferRevision::class)->orderBy('id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(OfferMessage::class)->orderBy('id');
    }

    public function isLive(): bool
    {
        return in_array($this->status, OfferStatus::LIVE, true) && $this->expires_at->isFuture();
    }

    /** Status as the client should see it — a live offer past its expiry reads as expired even before the sweep runs. */
    public function effectiveStatus(): string
    {
        return in_array($this->status, OfferStatus::LIVE, true) && ! $this->expires_at->isFuture()
            ? OfferStatus::EXPIRED
            : $this->status;
    }
}
