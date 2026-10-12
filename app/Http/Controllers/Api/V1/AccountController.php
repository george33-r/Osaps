<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Measurements\MeasurementSet;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The signed-in person's own settings, notifications and measurements. */
class AccountController extends Controller
{
    public function update(Request $request): UserResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'min:2', 'max:80'],
            'phone' => ['nullable', 'string', 'regex:/^\+?[0-9 ]{7,20}$/'],
            'city' => ['nullable', 'string', 'max:80'],
            'whatsapp_opt_in' => ['sometimes', 'boolean'],
            'currency' => ['nullable', 'in:NGN,GBP,USD,EUR,CAD'],
        ]);
        $user = $request->user();
        if (($data['whatsapp_opt_in'] ?? false) && empty($data['phone'] ?? $user->phone)) {
            abort(response()->json(['message' => 'Add your phone number to get WhatsApp updates.', 'code' => 'validation_failed', 'errors' => ['phone' => ['Add your WhatsApp number.']]], 422));
        }
        $user->forceFill($data)->save();

        return new UserResource($user);
    }

    public function notifications(Request $request): JsonResponse
    {
        $q = UserNotification::query()->where('user_id', $request->user()->id);

        return response()->json([
            'data' => (clone $q)->latest('id')->limit(30)->get()->map(fn ($n) => [
                'id' => $n->id, 'kind' => $n->kind, 'title' => $n->title, 'body' => $n->body, 'link' => $n->link,
                'read' => $n->read_at !== null, 'at' => $n->created_at?->toIso8601String(),
            ]),
            'meta' => ['unread' => (clone $q)->whereNull('read_at')->count()],
        ]);
    }

    public function markRead(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['nullable', 'array', 'max:100'], 'ids.*' => ['integer']]);
        UserNotification::query()->where('user_id', $request->user()->id)->whereNull('read_at')
            ->when(! empty($data['ids']), fn ($q) => $q->whereIn('id', $data['ids']))
            ->update(['read_at' => now()]);

        return response()->json(['data' => ['ok' => true]]);
    }

    public function measurements(Request $request): JsonResponse
    {
        $m = $request->user()->measurements;

        return response()->json(['data' => self::presentMeasurements($m)]);
    }

    public function saveMeasurements(Request $request): JsonResponse
    {
        $data = $request->validate([
            'unit' => ['required', 'in:cm,in'],
            'values' => ['required', 'array', 'max:20'],
            'notes' => ['nullable', 'string', 'max:500'],
            'share_with_tailors' => ['boolean'],
        ]);
        $values = MeasurementSet::normalise($data['values'], $data['unit']);
        $m = $request->user()->measurements()->updateOrCreate([], [
            'unit' => $data['unit'], 'values' => $values, 'notes' => $data['notes'] ?? null,
            'share_with_tailors' => (bool) ($data['share_with_tailors'] ?? true),
        ]);

        return response()->json(['data' => self::presentMeasurements($m)]);
    }

    public static function presentMeasurements($m): array
    {
        return [
            'unit' => $m?->unit ?? 'cm',
            'values' => $m?->values ?? (object) [],
            'notes' => $m?->notes,
            'share_with_tailors' => $m?->share_with_tailors ?? true,
            'updated_at' => $m?->updated_at?->toIso8601String(),
            'fields' => collect(MeasurementSet::FIELDS)->map(fn ($f, $k) => ['key' => $k, 'label' => $f[0]])->values(),
        ];
    }
}
