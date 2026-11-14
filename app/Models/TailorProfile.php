<?php

namespace App\Models;

use App\Domain\Orders\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TailorProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'business_name', 'slug', 'bio', 'city', 'service_cities', 'specialties', 'portfolio_tags',
        'weekly_capacity', 'experience_years', 'starting_price_minor', 'accepting_work',
    ];

    protected function casts(): array
    {
        return [
            'service_cities' => 'array',
            'specialties' => 'array',
            'portfolio_tags' => 'array',
            'accepting_work' => 'boolean',
            'experience_years' => 'integer',
            'is_demo' => 'boolean',
            'verified_at' => 'datetime',
            'verification_requested_at' => 'datetime',
            'verification_consent_at' => 'datetime',
            'recheck_due_at' => 'datetime',
            'recheck_reminded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(TailorVerificationDocument::class, 'tailor_profile_id');
    }

    public function approvedSelfie(): ?TailorVerificationDocument
    {
        return $this->documents()->where('kind', 'selfie')->where('status', 'approved')->latest('id')->first();
    }

    public function isVerified(): bool
    {
        return $this->verification_status === 'verified';
    }

    /** Verified, and the six-week check is not overdue. Public badges and the public selfie follow this. */
    public function badgeActive(): bool
    {
        return $this->isVerified() && \App\Domain\Verification\Recheck::state($this->recheck_due_at, now()) !== \App\Domain\Verification\Recheck::OVERDUE;
    }

    public function scopeAcceptingWork(Builder $q): Builder
    {
        return $q->where('accepting_work', true);
    }

    /**
     * Ratings from published customer reviews only. Null average means no reviews yet (unrated),
     * which is not the same as a low rating.
     *
     * @return array{count:int, average:?float, breakdown:?array<string,?float>}
     */
    public function reviewSummary(): array
    {
        $published = TailorReview::query()->published()->where('tailor_id', $this->user_id);
        $count = (clone $published)->count();
        if ($count === 0) {
            return ['count' => 0, 'average' => null, 'breakdown' => null];
        }

        $row = (clone $published)->selectRaw('AVG(stars) as stars, AVG(quality) as quality, AVG(communication) as communication, AVG(delivery_time) as delivery_time, AVG(value_for_money) as value_for_money')->first();
        $one = fn ($v) => $v === null ? null : round((float) $v, 1);

        return [
            'count' => $count,
            'average' => $one($row->stars),
            'breakdown' => [
                'quality' => $one($row->quality),
                'communication' => $one($row->communication),
                'delivery_time' => $one($row->delivery_time),
                'value_for_money' => $one($row->value_for_money),
            ],
        ];
    }

    /**
     * Track record computed from real orders only — never self-reported or seeded.
     *
     * @return array{completed_orders:int,on_time_orders:int,upheld_complaints:int,open_orders:int}
     */
    public function trackRecord(): array
    {
        $orders = Order::query()->where('tailor_id', $this->user_id);

        $completed = (clone $orders)->where('status', OrderStatus::COMPLETED);

        return [
            'completed_orders' => (clone $completed)->count(),
            'on_time_orders' => (clone $completed)
                ->whereNotNull('due_on')
                ->whereExists(fn ($q) => $q->from('order_events')
                    ->whereColumn('order_events.order_id', 'orders.id')
                    ->where('order_events.to_status', OrderStatus::DELIVERED)
                    ->whereRaw('date(order_events.created_at) <= orders.due_on'))
                ->count(),
            // Complaints count only once an admin upholds them (dispute module, later phase).
            'upheld_complaints' => 0,
            'open_orders' => (clone $orders)->whereNotIn('status', [OrderStatus::COMPLETED, OrderStatus::CANCELLED])->count(),
        ];
    }
}
