<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/** Reference data clients need to build forms and explain rules. Safe to cache. */
class MetaController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $kv = fn (array $map) => collect($map)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values();

        return response()->json(['data' => [
            'brand' => config('marketplace.brand'),
            'currency' => config('marketplace.currency'),
            'garment_categories' => $kv(config('marketplace.garment_categories')),
            'occasions' => $kv(config('marketplace.occasions')),
            'support' => array_filter(config('marketplace.support')),
            'fabric_categories' => $kv(config('marketplace.fabric_categories')),
            'fabric_units' => config('marketplace.fabric_units'),
            'colors' => config('marketplace.colors'),
            'fees' => [
                'customer_service_fee_bps' => config('marketplace.fees.customer_service_fee_bps'),
                'customer_service_fee_cap_minor' => config('marketplace.fees.customer_service_fee_cap_minor'),
            ],
            'offer_rules' => [
                'expiry_hours' => config('marketplace.offers.expiry_hours'),
                'max_counter_rounds_per_side' => config('marketplace.offers.max_counter_rounds_per_side'),
            ],
            'shop' => [
                'service_fee_bps' => config('marketplace.shop.service_fee_bps'),
                'service_fee_cap_minor' => config('marketplace.shop.service_fee_cap_minor'),
                'delivery_fee_per_vendor_minor' => config('marketplace.shop.delivery_fee_per_vendor_minor'),
                'checkout_hold_minutes' => config('marketplace.shop.checkout_hold_minutes'),
                'offer_floor_bps' => config('marketplace.shop.offers.floor_bps'),
                'offer_response_hours' => config('marketplace.shop.offers.response_hours'),
                'offer_hold_hours' => config('marketplace.shop.offers.hold_hours'),
                'offer_max_rounds' => config('marketplace.shop.offers.max_rounds_per_side'),
                'pickup_enabled' => (bool) config('marketplace.shop.pickup_enabled'),
                'protection_days' => (int) config('marketplace.shop.auto_complete_days', 7),
                'delivery' => [
                    'zones' => app(\App\Domain\Shop\DeliveryZones::class)->table(),
                    'states' => \App\Domain\Shop\DeliveryZones::STATES,
                    'lowest_fee_minor' => app(\App\Domain\Shop\DeliveryZones::class)->lowestFee(),
                ],
                'piece_yards' => array_filter(array_map('intval', (array) config('marketplace.shop.piece_yards'))),
                // Rough yards per garment, to help shoppers choose a quantity. A guide, not a promise.
                'typical_yards' => config('marketplace.typical_yards'),
            ],
            'diaspora' => [
                'rates' => app(\App\Domain\Diaspora\CurrencyDisplay::class)->rates(),
                'rates_updated' => config('marketplace.diaspora.rates_updated'),
                'ship_countries' => collect(\App\Domain\Diaspora\ShippingZones::COUNTRIES)
                    ->filter(fn ($zone) => (int) (config('marketplace.diaspora.shipping_minor')[$zone] ?? 0) > 0)
                    ->map(fn ($zone, $code) => ['code' => $code, 'fee_minor' => (int) config('marketplace.diaspora.shipping_minor')[$zone]])
                    ->values(),
            ],
            'features' => [
                'whatsapp' => app(\App\Messaging\WhatsAppClient::class)->enabled(),
                'try_on' => app(\App\TryOn\TryOnProvider::class)->available(),
                // True once a Paystack secret key is set on the server. The key itself is never sent.
                'payments' => app(\App\Payments\PaymentGateway::class)->enabled(),
                'ai_assistant' => app(\App\Ai\AiGateway::class)->available(),
                'disputes' => (bool) config('marketplace.disputes_enabled'),
            ],
        ]])->setPublic()->setMaxAge(300);
    }
}
