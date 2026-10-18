<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Negotiation\OfferStatus;
use App\Domain\Support\DomainRuleViolation;
use App\Http\Controllers\Controller;
use App\Models\Offer;
use App\Models\OfferMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Messages between a customer and one tailor about one offer — the place to ask the
 * "worth asking before you accept" questions. Only the two parties can read or write.
 */
class OfferMessageController extends Controller
{
    public function index(Request $request, Offer $offer): JsonResponse
    {
        Gate::authorize('view', $offer);

        $messages = $offer->messages()->latest('id')->limit(100)->get()->reverse()->values();

        return response()->json(['data' => $messages->map(fn (OfferMessage $m) => $this->present($m, $request))]);
    }

    public function store(Request $request, Offer $offer, \App\Services\PrivatePhotoService $photos): JsonResponse
    {
        Gate::authorize('message', $offer);
        // Text, a photo (e.g. "like this sleeve"), or both.
        $data = $request->validate([
            'body' => [$request->hasFile('photo') ? 'nullable' : 'required', 'string', 'min:2', 'max:1000'],
            'photo' => ['nullable', 'file', 'max:8192'],
        ]);

        if (! in_array($offer->effectiveStatus(), [...OfferStatus::LIVE, OfferStatus::ACCEPTED], true)) {
            throw new DomainRuleViolation('This offer is closed, so messages are turned off.', 'offer_closed');
        }

        $user = $request->user();
        $photo = $request->hasFile('photo') ? $photos->store($request->file('photo'), 'chat/'.$offer->id) : null;
        $message = $offer->messages()->create([
            'author_id' => $user->id,
            'author_role' => $user->id === $offer->tailor_id ? 'tailor' : 'customer',
            'body' => trim((string) ($data['body'] ?? '')),
            'photo_path' => $photo['path'] ?? null,
            'photo_mime' => $photo['mime'] ?? null,
        ]);

        return response()->json(['data' => $this->present($message, $request)], 201);
    }

    /** @return array<string,mixed> */
    private function present(OfferMessage $m, Request $request): array
    {
        return [
            'id' => $m->id,
            'author_role' => $m->author_role,
            'mine' => $m->author_id === $request->user()->id,
            'body' => $m->body,
            'photo_url' => $m->photo_path ? url("/api/v1/chat-photos/{$m->id}") : null,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
