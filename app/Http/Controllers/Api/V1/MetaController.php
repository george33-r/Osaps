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
            'features' => [
                'payments' => false,
                'ai_assistant' => app(\App\Ai\AiGateway::class)->available(),
                'disputes' => (bool) config('marketplace.disputes_enabled'),
            ],
        ]])->setPublic()->setMaxAge(300);
    }
}
