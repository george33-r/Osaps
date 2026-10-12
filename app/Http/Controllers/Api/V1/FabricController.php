<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FabricResource;
use App\Models\Fabric;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class FabricController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'category' => ['nullable', 'array'],
            'category.*' => [Rule::in(array_keys(config('marketplace.fabric_categories')))],
            'color' => ['nullable', 'array'],
            'color.*' => ['string', 'max:24'],
            'min_price' => ['nullable', 'integer', 'min:0'],
            'max_price' => ['nullable', 'integer', 'min:0'],
            'in_stock' => ['nullable', 'boolean'],
            'city' => ['nullable', 'string', 'max:80'],
            'sort' => ['nullable', Rule::in(['newest', 'price_asc', 'price_desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:48'],
        ]);

        $query = Fabric::query()->listed()->with('vendor', 'media')->filter($f);

        match ($f['sort'] ?? 'newest') {
            'price_asc' => $query->orderBy('price_minor')->orderBy('id'),
            'price_desc' => $query->orderByDesc('price_minor')->orderBy('id'),
            default => $query->latest('id'),
        };

        return FabricResource::collection($query->paginate($f['per_page'] ?? 24)->withQueryString());
    }

    /**
     * How to reach the vendor. Signed-in users only, so contact details are not scraped from public pages.
     * Purchases are arranged directly with the vendor until on-platform checkout exists.
     */
    public function contact(string $slug): \Illuminate\Http\JsonResponse
    {
        $fabric = Fabric::query()->listed()->with('vendor', 'media')->where('slug', $slug)->firstOrFail();
        $v = $fabric->vendor;

        return response()->json(['data' => [
            'business_name' => $v->business_name,
            'market' => $v->market,
            'city' => $v->city,
            'phone' => $v->phone,
            'whatsapp' => $v->whatsapp,
            'note' => $v->contact_note,
            'purchase_mode' => 'contact_vendor',
            'protected_by_osaps' => false,
        ]]);
    }

    public function show(string $slug): FabricResource
    {
        return new FabricResource(Fabric::query()->listed()->with('vendor', 'media')->where('slug', $slug)->firstOrFail());
    }
}
