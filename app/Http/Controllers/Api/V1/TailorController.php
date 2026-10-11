<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\UpsertTailorProfileRequest;
use App\Http\Resources\TailorResource;
use App\Models\TailorProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TailorController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $f = $request->validate([
            'city' => ['nullable', 'string', 'max:80'],
            'specialty' => ['nullable', Rule::in(array_keys(config('marketplace.garment_categories')))],
            'q' => ['nullable', 'string', 'max:80'],
        ]);

        $tailors = TailorProfile::query()->acceptingWork()
            ->when($f['city'] ?? null, fn ($q, $c) => $q->where('city', $c))
            ->when($f['specialty'] ?? null, fn ($q, $s) => $q->whereJsonContains('specialties', $s))
            ->when($f['q'] ?? null, fn ($q, $t) => $q->where('business_name', 'like', '%'.$t.'%'))
            // Verified first, then alphabetical. No paid placement, no ranking by price.
            ->orderByRaw("case when verification_status = 'verified' then 0 else 1 end")
            ->orderBy('business_name')
            ->paginate(24)->withQueryString();

        return TailorResource::collection($tailors);
    }

    public function show(string $slug): TailorResource
    {
        return new TailorResource(TailorProfile::query()->where('slug', $slug)->firstOrFail());
    }

    /** Tailor onboarding: create or update the signed-in tailor's own profile. */
    public function upsertMine(UpsertTailorProfileRequest $request): JsonResponse
    {
        $user = $request->user();
        $profile = $user->tailorProfile ?? new TailorProfile;
        $profile->fill($request->validated());
        $profile->user_id = $user->id;
        if (! $profile->exists) {
            $profile->slug = $this->uniqueSlug($profile->business_name);
        }
        $profile->save();

        return (new TailorResource($profile))->response()->setStatusCode($profile->wasRecentlyCreated ? 201 : 200);
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'tailor';
        $slug = $base;
        for ($i = 2; TailorProfile::query()->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }
}
