<?php

namespace App\Providers;

use App\Domain\Matching\TailorScorer;
use App\Domain\Negotiation\OfferRules;
use App\Domain\Pricing\FeeSchedule;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OfferRules::class, fn () => new OfferRules(
            offerExpiryHours: (int) config('marketplace.offers.expiry_hours'),
            maxCounterRoundsPerSide: (int) config('marketplace.offers.max_counter_rounds_per_side'),
            maxLiveOffersPerTailor: (int) config('marketplace.offers.max_live_offers_per_tailor'),
            maxOffersPerRequest: (int) config('marketplace.offers.max_offers_per_request'),
            minExplanationLength: (int) config('marketplace.offers.min_explanation_length'),
        ));

        $this->app->singleton(FeeSchedule::class, fn () => new FeeSchedule(
            customerServiceFeeBps: (int) config('marketplace.fees.customer_service_fee_bps'),
            customerServiceFeeCapMinor: (int) config('marketplace.fees.customer_service_fee_cap_minor'),
            tailorCommissionBps: (int) config('marketplace.fees.tailor_commission_bps'),
        ));

        $this->app->singleton(TailorScorer::class, fn () => new TailorScorer(config('marketplace.matching.weights')));
    }

    public function boot(): void
    {
        // Catch lazy loading, silently discarded attributes and missing attributes during development.
        Model::shouldBeStrict(! $this->app->isProduction());

        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));

        RateLimiter::for('auth', fn (Request $r) => [
            Limit::perMinute(10)->by($r->ip()),
            Limit::perMinute(5)->by(strtolower((string) $r->input('email')).'|'.$r->ip()),
        ]);

        // Spam protection for marketplace writes (posting requests, offers, counters).
        RateLimiter::for('marketplace-writes', fn (Request $r) => Limit::perMinute(20)->by($r->user()?->id ?: $r->ip()));
    }
}
