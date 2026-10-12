<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Shop\PriceOfferStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\PriceOfferResource;
use App\Models\Fabric;
use App\Models\PriceOffer;
use App\Services\ShopOfferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** inDrive-style "name your price" on fabric, for both the buyer and the vendor. */
class PriceOfferController extends Controller
{
    private const WITH = ['fabric.vendor', 'vendor', 'buyer', 'rounds'];

    public function __construct(private readonly ShopOfferService $offers) {}

    public function store(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate([
            'quantity' => ['required', 'integer', 'min:1', 'max:10000'],
            'unit_price_minor' => ['required', 'integer', 'min:100', 'max:100000000000'],
            'note' => ['nullable', 'string', 'max:200'],
        ]);
        $fabric = Fabric::query()->where('slug', $slug)->firstOrFail();
        $offer = $this->offers->open($request->user(), $fabric, (int) $data['quantity'], (int) $data['unit_price_minor'], $data['note'] ?? null);

        return $this->one($offer->load(self::WITH), 'buyer')->response()->setStatusCode(201);
    }

    /** The signed-in buyer's offers, newest first. */
    public function mine(Request $request): JsonResponse
    {
        $this->offers->expireStale();
        $offers = PriceOffer::query()->where('buyer_id', $request->user()->id)->with(self::WITH)->latest('updated_at')->limit(50)->get();

        return response()->json([
            'data' => $offers->map(fn ($o) => (new PriceOfferResource($o))->withExtra(['side' => 'buyer'])->toArray($request))->values(),
        ]);
    }

    /** Offers waiting on, or recently handled by, the signed-in vendor. */
    public function vendor(Request $request): JsonResponse
    {
        $vendor = $request->user()->fabricVendor;
        if ($vendor === null) {
            return response()->json(['data' => [], 'meta' => ['is_vendor' => false]]);
        }
        $this->offers->expireStale();
        $offers = PriceOffer::query()->where('fabric_vendor_id', $vendor->id)->with(self::WITH)
            ->orderByRaw("CASE WHEN status = ? THEN 0 ELSE 1 END", [PriceOfferStatus::AWAITING_VENDOR])
            ->latest('updated_at')->limit(100)->get();

        return response()->json([
            'data' => $offers->map(fn ($o) => (new PriceOfferResource($o))->withExtra(['side' => 'vendor'])->toArray($request))->values(),
            'meta' => ['is_vendor' => true],
        ]);
    }

    public function show(Request $request, PriceOffer $priceOffer): PriceOfferResource
    {
        return $this->one($priceOffer->load(self::WITH), $this->side($request, $priceOffer));
    }

    public function accept(Request $request, PriceOffer $priceOffer): PriceOfferResource
    {
        $side = $this->side($request, $priceOffer);
        $data = $request->validate(['expected_unit_price_minor' => ['required', 'integer', 'min:0']]);

        return $this->one($this->offers->accept($request->user(), $priceOffer, (int) $data['expected_unit_price_minor'])->load(self::WITH), $side);
    }

    public function counter(Request $request, PriceOffer $priceOffer): PriceOfferResource
    {
        $side = $this->side($request, $priceOffer);
        $data = $request->validate(['unit_price_minor' => ['required', 'integer', 'min:100', 'max:100000000000']]);

        return $this->one($this->offers->counter($request->user(), $priceOffer, (int) $data['unit_price_minor'])->load(self::WITH), $side);
    }

    public function decline(Request $request, PriceOffer $priceOffer): PriceOfferResource
    {
        $side = $this->side($request, $priceOffer);

        return $this->one($this->offers->decline($request->user(), $priceOffer)->load(self::WITH), $side);
    }

    public function withdraw(Request $request, PriceOffer $priceOffer): PriceOfferResource
    {
        $side = $this->side($request, $priceOffer);

        return $this->one($this->offers->withdraw($request->user(), $priceOffer)->load(self::WITH), $side);
    }

    private function side(Request $request, PriceOffer $offer): string
    {
        $side = $this->offers->sideOf($request->user(), $offer);
        abort_if($side === null, 403);

        return $side;
    }

    private function one(PriceOffer $offer, string $side): PriceOfferResource
    {
        return (new PriceOfferResource($offer))->withExtra(['side' => $side]);
    }
}
