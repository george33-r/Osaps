<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Live\LiveLink;
use App\Http\Controllers\Controller;
use App\Http\Resources\FabricResource;
use App\Models\Fabric;
use App\Models\LiveSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Vendors selling live from the market on TikTok, Instagram, YouTube or Facebook. */
class LiveController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sessions = LiveSession::query()->with('vendor')
            ->where('starts_at', '<=', now()->addDays(14))
            ->where(fn ($q) => $q->where('ends_at', '>', now())->orWhere(fn ($w) => $w->whereNull('ends_at')->where('starts_at', '>', now()->subHours(2))))
            ->orderBy('starts_at')->limit(40)->get();

        return response()->json(['data' => $this->present($request, $sessions)]);
    }

    public function mine(Request $request): JsonResponse
    {
        $vendor = $request->user()->fabricVendor;
        abort_if($vendor === null, 403);

        return response()->json(['data' => $this->present($request, $vendor->liveSessions()->with('vendor')->where('starts_at', '>', now()->subDays(2))->orderBy('starts_at')->get())]);
    }

    public function store(Request $request): JsonResponse
    {
        $vendor = $request->user()->fabricVendor;
        abort_if($vendor === null, 403);
        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:100'],
            'platform' => ['required', 'in:tiktok,instagram,youtube,facebook'],
            'url' => ['required', 'string', 'max:300'],
            'starts_at' => ['required', 'date', 'after:-1 hour', 'before:+30 days'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'fabric_ids' => ['nullable', 'array', 'max:12'],
            'fabric_ids.*' => ['integer'],
        ]);
        LiveLink::assertValid($data['platform'], $data['url']);
        // Only the vendor's own fabrics can be featured.
        $data['fabric_ids'] = $vendor->fabrics()->whereIn('id', $data['fabric_ids'] ?? [])->pluck('id')->all();
        $s = $vendor->liveSessions()->create($data);

        return response()->json(['data' => $this->present($request, collect([$s->load('vendor')]))[0]], 201);
    }

    public function destroy(Request $request, LiveSession $liveSession): JsonResponse
    {
        abort_unless($request->user()->fabricVendor?->id === $liveSession->fabric_vendor_id, 403);
        $liveSession->delete();

        return response()->json(['data' => ['ok' => true]]);
    }

    private function present(Request $request, $sessions): array
    {
        $ids = $sessions->pluck('fabric_ids')->flatten()->filter()->unique()->values();
        $fabrics = Fabric::query()->with('vendor', 'media')->listed()->whereIn('id', $ids)->get()->keyBy('id');

        return $sessions->map(fn (LiveSession $s) => [
            'id' => $s->id,
            'title' => $s->title,
            'platform' => $s->platform,
            'url' => $s->url,
            'youtube_id' => $s->platform === 'youtube' ? LiveLink::youtubeId($s->url) : null,
            'starts_at' => $s->starts_at->toIso8601String(),
            'ends_at' => $s->ends_at?->toIso8601String(),
            'is_live' => $s->isLive(),
            'vendor' => ['business_name' => $s->vendor?->business_name, 'market' => $s->vendor?->market, 'city' => $s->vendor?->city],
            'fabrics' => collect($s->fabric_ids ?? [])->map(fn ($id) => $fabrics[$id] ?? null)->filter()->map(fn ($f) => (new FabricResource($f))->toArray($request))->values(),
        ])->values()->all();
    }
}
