<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FabricResource;
use App\Models\Fabric;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** A vendor keeps their own price and stock up to date. */
class VendorFabricController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $vendor = $request->user()->fabricVendor;
        abort_if($vendor === null, 403);

        $fabrics = $vendor->fabrics()->with('vendor')->orderBy('name')->get();

        return response()->json(['data' => $fabrics->map(fn ($f) => (new FabricResource($f))->toArray($request) + [
            'status' => $f->status,
            'stock_quantity' => $f->stock_quantity,
            'reserved_quantity' => $f->reserved_quantity,
        ])->values()]);
    }

    /** Create or update the vendor's shop details. */
    public function upsertProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role === 'fabric_vendor', 403);
        $data = $request->validate([
            'business_name' => ['required', 'string', 'min:2', 'max:80'],
            'city' => ['required', 'string', 'max:80'],
            'market' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9 ]{7,20}$/'],
            'whatsapp' => ['nullable', 'string', 'regex:/^\+?[0-9 ]{7,20}$/'],
        ]);
        $vendor = $user->fabricVendor()->firstOrNew([]);
        if (! $vendor->exists) {
            $vendor->slug = \Illuminate\Support\Str::slug($data['business_name']).'-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(4));
        }
        $vendor->fill($data);
        $vendor->save();

        return response()->json(['data' => [
            'business_name' => $vendor->business_name, 'slug' => $vendor->slug, 'city' => $vendor->city, 'market' => $vendor->market,
            'phone' => $vendor->phone, 'whatsapp' => $vendor->whatsapp, 'verified' => $vendor->verification_status === 'verified',
        ]]);
    }

    /** Add a new listing. It goes live straight away unless saved as a draft. */
    public function store(Request $request): JsonResponse
    {
        $vendor = $request->user()->fabricVendor;
        abort_if($vendor === null, 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:80'],
            'category' => ['required', \Illuminate\Validation\Rule::in(array_keys(config('marketplace.fabric_categories')))],
            'description' => ['nullable', 'string', 'max:1000'],
            'primary_color' => ['required', \Illuminate\Validation\Rule::in(config('marketplace.colors'))],
            'colors' => ['nullable', 'array', 'max:5'],
            'colors.*' => [\Illuminate\Validation\Rule::in(config('marketplace.colors'))],
            'pattern' => ['nullable', 'string', 'max:40'],
            'material' => ['nullable', 'string', 'max:80'],
            'unit' => ['required', \Illuminate\Validation\Rule::in(config('marketplace.fabric_units'))],
            'price_minor' => ['required', 'integer', 'min:100', 'max:100000000000'],
            'stock_quantity' => ['required', 'integer', 'min:0', 'max:1000000'],
            'min_order_quantity' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'status' => ['nullable', 'in:active,draft'],
        ]);
        $fabric = new Fabric([
            ...array_diff_key($data, array_flip(['colors', 'min_order_quantity', 'status'])),
            'colors' => array_values(array_unique(array_merge([$data['primary_color']], $data['colors'] ?? []))),
            'min_order_quantity' => (int) ($data['min_order_quantity'] ?? 1),
            'status' => $data['status'] ?? 'active',
        ]);
        $fabric->slug = \Illuminate\Support\Str::slug($data['name']).'-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(4));
        $fabric->fabric_vendor_id = $vendor->id;
        $fabric->save();

        return response()->json(['data' => (new FabricResource($fabric->load('vendor')))->toArray($request) + [
            'status' => $fabric->status, 'stock_quantity' => $fabric->stock_quantity, 'reserved_quantity' => 0,
        ]], 201);
    }

    public function update(Request $request, Fabric $fabric): JsonResponse
    {
        $vendor = $request->user()->fabricVendor;
        abort_unless($vendor !== null && $fabric->fabric_vendor_id === $vendor->id, 403);
        $data = $request->validate([
            'price_minor' => ['sometimes', 'integer', 'min:100', 'max:100000000000'],
            'stock_quantity' => ['sometimes', 'integer', 'min:0', 'max:1000000'],
            'status' => ['sometimes', 'in:active,draft,archived'],
        ]);

        $fabric = DB::transaction(function () use ($fabric, $data) {
            $fabric = Fabric::query()->lockForUpdate()->findOrFail($fabric->id);
            if (isset($data['stock_quantity']) && $data['stock_quantity'] < $fabric->reserved_quantity) {
                throw ValidationException::withMessages(['stock_quantity' => ["{$fabric->reserved_quantity} are held for buyers who are paying now. Stock cannot go below that."]]);
            }
            $fabric->forceFill($data)->save();

            return $fabric;
        });

        return response()->json(['data' => (new FabricResource($fabric->load('vendor')))->toArray($request) + [
            'status' => $fabric->status,
            'stock_quantity' => $fabric->stock_quantity,
            'reserved_quantity' => $fabric->reserved_quantity,
        ]]);
    }
}
