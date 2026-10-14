<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Shop\VendorVerification;
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

        $fabrics = $vendor->fabrics()->with('vendor', 'media')->orderBy('name')->get();

        return response()->json(['data' => $fabrics->map(fn ($f) => (new FabricResource($f))->toArray($request) + [
            'status' => $f->status,
            'stock_quantity' => $f->stock_quantity,
            'reserved_quantity' => $f->reserved_quantity,
        ])->values()]);
    }

    /**
     * Create or update the vendor's shop details. A complete profile is sent for review
     * automatically. Changing bank details on a verified shop sends it back for review, so a
     * stolen login cannot quietly redirect payouts.
     */
    public function upsertProfile(Request $request, \App\Services\TrustService $trust): JsonResponse
    {
        $user = $request->user();
        abort_unless($user->role === 'fabric_vendor', 403);
        $data = $request->validate([
            'business_name' => ['required', 'string', 'min:2', 'max:80'],
            'city' => ['required', 'string', 'max:80'],
            'market' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9 ]{7,20}$/'],
            'whatsapp' => ['nullable', 'string', 'regex:/^\+?[0-9 ]{7,20}$/'],
            'bank_name' => ['nullable', 'string', 'max:80'],
            'bank_account_number' => ['nullable', 'string', 'regex:/^[0-9]{10}$/'],
            'bank_account_name' => ['nullable', 'string', 'max:120'],
        ]);
        $vendor = $user->fabricVendor()->firstOrNew([]);
        if (! $vendor->exists) {
            $vendor->slug = \Illuminate\Support\Str::slug($data['business_name']).'-'.\Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(4));
            $vendor->verification_status = VendorVerification::UNVERIFIED;
        }
        // An empty account number field means "keep the saved one" (the form only shows the last 4 digits).
        if (($data['bank_account_number'] ?? null) === null) {
            unset($data['bank_account_number']);
        }
        $bankChanged = $vendor->exists && (
            (array_key_exists('bank_account_number', $data) && $data['bank_account_number'] !== $vendor->bank_account_number)
            || ($data['bank_name'] ?? null) !== $vendor->bank_name || ($data['bank_account_name'] ?? null) !== $vendor->bank_account_name);
        $vendor->fill($data);
        if ($bankChanged && $vendor->verification_status === VendorVerification::VERIFIED) {
            $vendor->verification_status = VendorVerification::PENDING;
            $vendor->verification_requested_at = now();
            $vendor->verification_note = 'Bank details changed. OSAPS checks them again before the shop goes back live.';
        }
        $vendor->save();
        if (in_array($vendor->verification_status, [VendorVerification::UNVERIFIED, VendorVerification::REJECTED], true)
            && VendorVerification::missingForReview($this->reviewFields($vendor)) === []) {
            $vendor = $trust->requestReview($vendor);
        }

        return response()->json(['data' => $this->presentProfile($vendor->fresh())]);
    }

    public function profile(Request $request): JsonResponse
    {
        $vendor = $request->user()->fabricVendor;
        abort_if($vendor === null, 404);

        return response()->json(['data' => $this->presentProfile($vendor)]);
    }

    /** @return array<string,mixed> */
    private function reviewFields(\App\Models\FabricVendor $vendor): array
    {
        return $vendor->only(['business_name', 'city', 'market', 'phone', 'bank_name', 'bank_account_name']) + ['bank_account_number' => $vendor->bank_account_number];
    }

    /** @return array<string,mixed> */
    private function presentProfile(\App\Models\FabricVendor $vendor): array
    {
        $status = $vendor->verification_status ?: VendorVerification::UNVERIFIED;

        return [
            'business_name' => $vendor->business_name, 'slug' => $vendor->slug, 'city' => $vendor->city, 'market' => $vendor->market,
            'phone' => $vendor->phone, 'whatsapp' => $vendor->whatsapp, 'verified' => $status === VendorVerification::VERIFIED,
            'verification' => [
                'status' => $status,
                'label' => VendorVerification::LABELS[$status] ?? $status,
                'note' => $vendor->verification_note,
                'missing' => VendorVerification::missingForReview($this->reviewFields($vendor)),
            ],
            'bank_name' => $vendor->bank_name, 'bank_account_name' => $vendor->bank_account_name,
            // Only the last four digits are ever sent back.
            'bank_account_last4' => $vendor->bank_account_number ? substr((string) $vendor->bank_account_number, -4) : null,
        ];
    }

    /** Add a photo or a short video of the real bolt. */
    public function addMedia(Request $request, Fabric $fabric, \App\Services\MediaService $media): JsonResponse
    {
        $vendor = $request->user()->fabricVendor;
        abort_unless($vendor !== null && $fabric->fabric_vendor_id === $vendor->id, 403);
        $request->validate(['file' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/webm,video/quicktime', 'max:25600']]);
        $file = $request->file('file');
        if (str_starts_with((string) $file->getMimeType(), 'image/') && $file->getSize() > 6 * 1024 * 1024) {
            throw ValidationException::withMessages(['file' => ['Photos can be up to 6 MB.']]);
        }
        $media->add($fabric, $file);

        return response()->json(['data' => $this->listing($request, $fabric->fresh(['vendor', 'media']))], 201);
    }

    public function removeMedia(Request $request, Fabric $fabric, int $mediaId, \App\Services\MediaService $media): JsonResponse
    {
        $vendor = $request->user()->fabricVendor;
        abort_unless($vendor !== null && $fabric->fabric_vendor_id === $vendor->id, 403);
        $item = $fabric->media()->whereKey($mediaId)->firstOrFail();
        $media->remove($item);

        return response()->json(['data' => $this->listing($request, $fabric->fresh(['vendor', 'media']))]);
    }

    private function listing(Request $request, Fabric $f): array
    {
        return (new FabricResource($f))->toArray($request) + [
            'status' => $f->status, 'stock_quantity' => $f->stock_quantity, 'reserved_quantity' => $f->reserved_quantity,
        ];
    }

    /** Add a new listing. Buyers see it once it is active and the shop is verified. */
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

        return response()->json(['data' => (new FabricResource($fabric->load('vendor', 'media')))->toArray($request) + [
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

        return response()->json(['data' => (new FabricResource($fabric->load('vendor', 'media')))->toArray($request) + [
            'status' => $fabric->status,
            'stock_quantity' => $fabric->stock_quantity,
            'reserved_quantity' => $fabric->reserved_quantity,
        ]]);
    }
}
