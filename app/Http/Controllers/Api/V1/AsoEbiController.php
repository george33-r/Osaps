<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\AsoEbiGroupResource;
use App\Http\Resources\CheckoutResource;
use App\Models\AsoEbiGroup;
use App\Models\Fabric;
use App\Services\AsoEbiService;
use App\Services\CheckoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AsoEbiController extends Controller
{
    private const WITH = ['fabric.vendor', 'fabric.media', 'organizer', 'shares.guest'];

    public function __construct(private readonly AsoEbiService $groups, private readonly CheckoutService $checkouts) {}

    /** Public: anyone with the link can see the group. */
    public function show(Request $request, AsoEbiGroup $asoEbiGroup): JsonResponse
    {
        $this->groups->closeExpired();

        return $this->present($request, $asoEbiGroup->fresh(self::WITH));
    }

    public function mine(Request $request): JsonResponse
    {
        $this->groups->closeExpired();
        $user = $request->user();
        $groups = AsoEbiGroup::query()->with(self::WITH)
            ->where(fn ($q) => $q->where('organizer_id', $user->id)->orWhereHas('shares', fn ($s) => $s->where('guest_id', $user->id)->where('status', 'paid')))
            ->latest('id')->limit(30)->get();

        return response()->json(['data' => $groups->map(fn ($g) => (new AsoEbiGroupResource($g))->withExtra(['viewer_is_organizer' => $g->organizer_id === $user->id])->toArray($request))->values()]);
    }

    public function store(Request $request, string $slug): JsonResponse
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'min:3', 'max:80'],
            'message' => ['nullable', 'string', 'max:500'],
            'event_date' => ['required', 'date_format:Y-m-d'],
            'deadline_at' => ['required', 'date'],
            'yards_per_guest' => ['required', 'integer', 'min:1', 'max:50'],
            'target_guests' => ['required', 'integer', 'min:2', 'max:500'],
            'fulfilment' => ['required', 'in:deliver_each,pickup_organizer'],
            'organizer_phone' => ['required', 'string', 'regex:/^\+?[0-9 ]{7,20}$/'],
            'price_offer_id' => ['nullable', 'integer', 'min:1'],
        ]);
        $fabric = Fabric::query()->where('slug', $slug)->firstOrFail();
        $group = $this->groups->create($request->user(), $fabric, $data);

        return $this->present($request, $group->fresh(self::WITH), 201);
    }

    public function quote(Request $request, AsoEbiGroup $asoEbiGroup): JsonResponse
    {
        $data = $request->validate(['yards' => ['required', 'integer', 'min:1', 'max:50'], 'state' => ['nullable', 'string', 'max:40']]);

        return response()->json(['data' => ShopController::present($this->checkouts->quoteShare($request->user(), $asoEbiGroup, (int) $data['yards'], $data['state'] ?? null))]);
    }

    public function join(Request $request, AsoEbiGroup $asoEbiGroup): JsonResponse
    {
        $delivery = $asoEbiGroup->fulfilment === 'deliver_each';
        $data = $request->validate([
            'yards' => ['required', 'integer', 'min:1', 'max:50'],
            'show_name' => ['boolean'],
            'expected_total_minor' => ['required', 'integer', 'min:0'],
            'contact.name' => ['required', 'string', 'min:2', 'max:80'],
            'contact.phone' => ['required', 'string', 'regex:/^\+?[0-9 ]{7,20}$/'],
            'address.line1' => [$delivery ? 'required' : 'nullable', 'string', 'max:160'],
            'address.line2' => ['nullable', 'string', 'max:160'],
            'address.city' => [$delivery ? 'required' : 'nullable', 'string', 'max:80'],
            'address.state' => [$delivery ? 'required' : 'nullable', 'string', 'max:80'],
            'address.landmark' => ['nullable', 'string', 'max:160'],
        ]);
        $user = $request->user();
        $checkout = $this->checkouts->createForShare(
            guest: $user,
            group: $asoEbiGroup,
            yards: (int) $data['yards'],
            showName: (bool) ($data['show_name'] ?? true),
            contact: ['name' => $data['contact']['name'], 'phone' => $data['contact']['phone'], 'email' => $user->email],
            address: $delivery ? array_filter($data['address'] ?? [], fn ($v) => $v !== null && $v !== '') : null,
            expectedTotalMinor: (int) $data['expected_total_minor'],
            callbackUrl: url('/checkout/complete'),
        );

        return (new CheckoutResource($checkout->load('orders.items.fabric', 'orders.vendor', 'orders.checkout')))
            ->withExtra(['authorization_url' => $checkout->authorization_url])
            ->response()->setStatusCode(201);
    }

    public function close(Request $request, AsoEbiGroup $asoEbiGroup): JsonResponse
    {
        return $this->present($request, $this->groups->close($request->user(), $asoEbiGroup)->fresh(self::WITH));
    }

    public function reopen(Request $request, AsoEbiGroup $asoEbiGroup): JsonResponse
    {
        return $this->present($request, $this->groups->reopen($request->user(), $asoEbiGroup)->fresh(self::WITH));
    }

    public function cancel(Request $request, AsoEbiGroup $asoEbiGroup): JsonResponse
    {
        return $this->present($request, $this->groups->cancel($request->user(), $asoEbiGroup)->fresh(self::WITH));
    }

    private function present(Request $request, AsoEbiGroup $group, int $status = 200): JsonResponse
    {
        $viewer = $request->user('sanctum');

        return response()->json(['data' => (new AsoEbiGroupResource($group))->withExtra(['viewer_is_organizer' => $viewer?->id === $group->organizer_id])->toArray($request)], $status);
    }
}
