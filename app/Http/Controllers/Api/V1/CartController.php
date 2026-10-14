<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\CartItem;
use App\Models\Fabric;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The signed-in shopper's cart, kept on the server so it follows them between phone and laptop.
 * It stores only what they picked (fabric, quantity, agreed offer). Prices are always re-quoted.
 */
class CartController extends Controller
{
    public const MAX_LINES = 50;

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->lines($request->user()->id)]);
    }

    /** Replace the whole cart with what the browser holds (after it merged any guest cart). */
    public function replace(Request $request): JsonResponse
    {
        $data = $request->validate([
            'items' => ['present', 'array', 'max:'.self::MAX_LINES],
            'items.*.fabric_id' => ['required', 'integer', 'distinct'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'items.*.price_offer_id' => ['nullable', 'integer'],
        ]);
        $userId = $request->user()->id;
        $known = Fabric::query()->whereIn('id', array_column($data['items'], 'fabric_id'))->pluck('id')->all();
        // An offer id is kept only if it is the shopper's own; checkout checks it again anyway.
        $offers = \App\Models\PriceOffer::query()->where('buyer_id', $userId)
            ->whereIn('id', array_filter(array_column($data['items'], 'price_offer_id')))->pluck('id')->all();

        DB::transaction(function () use ($data, $userId, $known, $offers) {
            CartItem::query()->where('user_id', $userId)->delete();
            foreach ($data['items'] as $item) {
                if (! in_array((int) $item['fabric_id'], $known, true)) {
                    continue;
                }
                $offer = isset($item['price_offer_id']) && in_array((int) $item['price_offer_id'], $offers, true) ? (int) $item['price_offer_id'] : null;
                CartItem::query()->create(['user_id' => $userId, 'fabric_id' => (int) $item['fabric_id'], 'quantity' => (int) $item['quantity'], 'price_offer_id' => $offer]);
            }
        });

        return response()->json(['data' => $this->lines($userId)]);
    }

    /**
     * Each line carries enough to draw the cart on a new device. Prices shown are the current
     * list price and any agreed offer; the checkout quote re-prices everything anyway.
     *
     * @return list<array<string,mixed>>
     */
    private function lines(int $userId): array
    {
        return CartItem::query()->where('user_id', $userId)->with('fabric.vendor', 'fabric.media', 'offer')->orderBy('id')->get()
            ->filter(fn (CartItem $i) => $i->fabric !== null)
            ->map(function (CartItem $i) {
                $f = $i->fabric;
                $offer = $i->offer !== null && $i->offer->isUsable() ? $i->offer : null;

                return [
                    'fabric_id' => $i->fabric_id, 'quantity' => $i->quantity, 'price_offer_id' => $offer?->id,
                    'fabric' => [
                        'slug' => $f->slug, 'name' => $f->name, 'unit' => $f->unit, 'colors' => $f->colors ?? [], 'pattern' => $f->pattern,
                        'image_url' => $f->media->firstWhere('kind', 'photo')?->url() ?? ($f->image_path ? asset('storage/'.$f->image_path) : null),
                        'vendor_name' => $f->vendor?->business_name, 'list_minor' => (int) $f->price_minor,
                        'min' => max(1, (int) $f->min_order_quantity), 'available' => $f->availableQuantity(),
                    ],
                    'offer' => $offer ? ['unit_minor' => (int) $offer->unit_minor, 'quantity' => (int) $offer->quantity] : null,
                ];
            })->values()->all();
    }
}
