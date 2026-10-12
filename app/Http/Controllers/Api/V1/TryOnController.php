<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Support\DomainRuleViolation;
use App\Http\Controllers\Controller;
use App\Models\Fabric;
use App\Models\TryOnRequest;
use App\TryOn\TryOnProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "See it on me": the person's own photo, in an outfit made from this fabric, drawn by an image AI.
 * The photo is sent to the AI provider and the result straight back; neither is stored by OSAPS.
 */
class TryOnController extends Controller
{
    private const GARMENTS = [
        'agbada' => 'a flowing Yoruba agbada with matching fila cap',
        'senator' => 'a tailored senator top and trousers',
        'kaftan' => 'a long kaftan',
        'iro_buba' => 'iro and buba with a matching gele head tie',
        'gown' => 'an elegant fitted gown',
        'wrapper' => 'a blouse with a double wrapper, Igbo style, with coral beads',
        'skirt_blouse' => 'a fitted skirt and blouse',
    ];

    public function __invoke(Request $request, TryOnProvider $provider): JsonResponse
    {
        if (! $provider->available()) {
            throw new DomainRuleViolation('Try-on is not switched on yet.', 'try_on_disabled');
        }
        $data = $request->validate([
            'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:6144'],
            'fabric_id' => ['required', 'integer'],
            'garment' => ['required', 'in:'.implode(',', array_keys(self::GARMENTS))],
            'consent' => ['accepted'],
        ]);
        $user = $request->user();
        $limit = (int) config('ai.try_on.daily_limit', 5);
        $used = TryOnRequest::query()->where('user_id', $user->id)->where('created_at', '>', now()->subDay())->count();
        if ($used >= $limit) {
            throw new DomainRuleViolation("You can make {$limit} try-on pictures a day. Come back tomorrow.", 'try_on_limit');
        }
        $globalLimit = (int) config('ai.try_on.daily_limit_global', 200);
        if (TryOnRequest::query()->where('created_at', '>', now()->subDay())->count() >= $globalLimit) {
            throw new DomainRuleViolation('Try-on is very busy today. Please try again tomorrow.', 'try_on_busy');
        }

        $fabric = Fabric::query()->with('media')->listed()->findOrFail($data['fabric_id']);
        $photoFile = $request->file('photo');
        $fabricPhoto = $fabric->media->firstWhere('kind', 'photo');
        $fabricBytes = $fabricPhoto && is_file(public_path('uploads/'.$fabricPhoto->path)) ? (string) file_get_contents(public_path('uploads/'.$fabricPhoto->path)) : null;

        $colours = implode(', ', array_map(fn ($c) => str_replace('_', ' ', $c), (array) $fabric->colors));
        $prompt = 'Edit the photo of this person so they are wearing '.self::GARMENTS[$data['garment']]
            .' made from '.($fabricBytes ? 'the fabric shown in the second image' : "a {$fabric->category} fabric with a ".($fabric->pattern ?: 'traditional').' pattern in '.$colours)
            .'. Keep the person\'s face, body, skin tone, pose and background exactly as they are. Realistic photograph, natural light. '
            .'Do not change their identity, do not add text or logos.';

        $result = 'error';
        try {
            $bytes = $provider->render((string) file_get_contents($photoFile->getRealPath()), (string) $photoFile->getMimeType(), $fabricBytes, $fabricPhoto?->mime, $prompt);
            $result = 'ok';
        } finally {
            TryOnRequest::query()->create(['user_id' => $user->id, 'fabric_id' => $fabric->id, 'result' => $result]);
            @unlink($photoFile->getRealPath());
        }

        return response()->json(['data' => [
            'image' => 'data:image/png;base64,'.base64_encode($bytes),
            'notice' => 'AI preview, not a photograph of the real outfit. Colours and fit will differ.',
            'left_today' => max(0, $limit - $used - 1),
        ]]);
    }
}
