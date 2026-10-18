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

        $this->app->singleton(\App\Ai\AiProvider::class, fn () => match (config('ai.provider')) {
            'anthropic' => new \App\Ai\Providers\AnthropicProvider(config('ai.anthropic'), (int) config('ai.timeout_seconds')),
            default => new \App\Ai\Providers\UnavailableProvider,
        });

        $this->app->singleton(\App\Domain\Shop\PriceOfferRules::class, fn () => new \App\Domain\Shop\PriceOfferRules(
            floorBps: (int) config('marketplace.shop.offers.floor_bps'),
            responseHours: (int) config('marketplace.shop.offers.response_hours'),
            holdHours: (int) config('marketplace.shop.offers.hold_hours'),
            maxRoundsPerSide: (int) config('marketplace.shop.offers.max_rounds_per_side'),
            maxLivePerBuyer: (int) config('marketplace.shop.offers.max_live_per_buyer'),
        ));

        $this->app->singleton(\App\Domain\Shop\CartPricing::class, fn () => new \App\Domain\Shop\CartPricing(
            serviceFeeBps: (int) config('marketplace.shop.service_fee_bps'),
            serviceFeeCapMinor: (int) config('marketplace.shop.service_fee_cap_minor'),
            deliveryFeePerVendorMinor: (int) config('marketplace.shop.delivery_fee_per_vendor_minor'),
            currency: (string) config('marketplace.currency', 'NGN'),
        ));

        $this->app->singleton(\App\Payments\PaymentGateway::class, fn () => new \App\Payments\PaystackGateway(
            secret: config('payments.paystack.secret_key'),
            baseUrl: (string) config('payments.paystack.base_url'),
            timeout: (int) config('payments.paystack.timeout'),
            channels: array_values((array) config('payments.paystack.channels')),
        ));

        $this->app->singleton(\App\Domain\AsoEbi\AsoEbiRules::class, fn () => new \App\Domain\AsoEbi\AsoEbiRules);
        $this->app->singleton(\App\Domain\Shop\PayoutRules::class, fn () => new \App\Domain\Shop\PayoutRules(
            autoCompleteDays: (int) config('marketplace.shop.auto_complete_days', 7),
            twoPersonPayouts: (bool) config('marketplace.shop.two_person_payouts', true),
        ));
        $this->app->singleton(\App\Domain\Shop\DeliveryZones::class, fn () => new \App\Domain\Shop\DeliveryZones(
            (array) config('marketplace.shop.delivery_zones'), (array) config('marketplace.shop.delivery_state_zones'),
        ));
        $this->app->singleton(\App\Domain\Diaspora\ShippingZones::class, fn () => new \App\Domain\Diaspora\ShippingZones(
            array_map('intval', (array) config('marketplace.diaspora.shipping_minor')),
        ));
        $this->app->singleton(\App\Domain\Diaspora\CurrencyDisplay::class, fn () => new \App\Domain\Diaspora\CurrencyDisplay(
            (array) config('marketplace.diaspora.naira_per_unit'),
        ));
        $this->app->singleton(\App\Messaging\WhatsAppClient::class, fn () => new \App\Messaging\WhatsAppClient(
            token: config('marketplace.whatsapp.token'),
            phoneNumberId: config('marketplace.whatsapp.phone_number_id'),
            template: (string) config('marketplace.whatsapp.template'),
            language: (string) config('marketplace.whatsapp.language'),
        ));
        $this->app->singleton(\App\TryOn\TryOnProvider::class, fn () => config('ai.try_on.provider') === 'openai' && config('ai.try_on.openai_key')
            ? new \App\TryOn\OpenAiTryOn(config('ai.try_on.openai_key'), (string) config('ai.try_on.model'), (int) config('ai.try_on.timeout'))
            : new \App\TryOn\UnavailableTryOn);

        $this->app->singleton(TailorScorer::class, fn () => new TailorScorer(config('marketplace.matching.weights')));
    }

    public function boot(): void
    {
        // Password reset emails link to the web app's own page, not a Laravel view.
        \Illuminate\Auth\Notifications\ResetPassword::createUrlUsing(
            fn ($user, string $token) => url('/reset-password').'?token='.urlencode($token).'&email='.urlencode($user->getEmailForPasswordReset())
        );

        // Strict Eloquent catches lazy loading and silently discarded attributes — in local and test runs only,
        // never on a live server, where it would turn a missed eager-load into a 500.
        Model::shouldBeStrict($this->app->environment('local', 'testing'));

        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(120)->by($r->user()?->id ?: $r->ip()));

        RateLimiter::for('auth', fn (Request $r) => [
            Limit::perMinute(10)->by($r->ip()),
            Limit::perMinute(5)->by(strtolower((string) $r->input('email')).'|'.$r->ip()),
        ]);

        // AI endpoints: a short burst limit at the HTTP layer (the AiGateway also enforces daily quotas).
        RateLimiter::for('ai', fn (Request $r) => Limit::perMinute(12)->by($r->user()?->id ?: $r->ip()));

        // Spam protection for marketplace writes (posting requests, offers, counters).
        // Cart re-pricing happens as the buyer edits quantities; generous but bounded.
        RateLimiter::for('shop-quotes', fn (Request $r) => Limit::perMinute(60)->by($r->user()?->id ?: $r->ip()));

        // Paystack webhooks come from a handful of Paystack IPs.
        RateLimiter::for('webhooks', fn (Request $r) => Limit::perMinute(300)->by($r->ip()));

        RateLimiter::for('marketplace-writes', fn (Request $r) => Limit::perMinute(20)->by($r->user()?->id ?: $r->ip()));
    }
}
