<?php

namespace App\Http\Controllers\Api\V1;

use App\Ai\AiException;
use App\Ai\AiGateway;
use App\Ai\Features\FabricIdeas;
use App\Ai\Features\FabricSearch;
use App\Ai\Features\InspirationBrief;
use App\Ai\Features\Stylist;
use App\Http\Controllers\Controller;
use App\Http\Resources\FabricResource;
use App\Http\Resources\TailorResource;
use App\Models\Fabric;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * AI-assisted features. Each response says where its content came from ("source"):
 *   ai       — written or interpreted by the configured model, then validated and grounded
 *   keywords / guided / guide — the rule-based fallback, used when AI is off or fails
 * Catalogue items and tailors in responses are always real records.
 */
class AiController extends Controller
{
    public function status(AiGateway $ai): JsonResponse
    {
        return response()->json(['data' => ['available' => $ai->available()]]);
    }

    public function fabricSearch(Request $request, FabricSearch $search): JsonResponse
    {
        $q = $request->validate(['q' => ['required', 'string', 'min:2', 'max:200']])['q'];
        $r = $search->search($q, $request->user(), $request->ip());

        return response()->json(['data' => [
            'source' => $r['source'],
            'understood' => $r['understood'],
            'criteria' => $r['criteria']->toArray(),
            'relaxed' => $r['relaxed'],
            'notice' => $r['notice'],
            'results' => FabricResource::collection($r['results'])->resolve($request),
        ]]);
    }

    public function stylist(Request $request, Stylist $stylist): JsonResponse
    {
        $data = $request->validate([
            'known' => ['nullable', 'array'],
            'message' => ['nullable', 'string', 'max:500'],
            'slot' => ['nullable', Rule::in(\App\Domain\Ai\StylistConsultation::SLOTS)],
        ]);

        $turn = $stylist->turn($data['known'] ?? [], $data['message'] ?? null, $data['slot'] ?? null, $request->user(), $request->ip());

        if ($turn['result'] !== null) {
            $r = $turn['result'];
            $turn['result'] = [
                'source' => $r['source'],
                'notice' => $r['notice'],
                'concept' => $r['concept'],
                'fabrics' => FabricResource::collection($r['fabrics'])->resolve($request),
                'tailors' => $r['tailors']->map(fn ($row) => (new TailorResource($row['profile']))->withExtra(['match' => $row['match']])->resolve($request))->values(),
                'budget' => $this->presentBudget($r['budget']),
                'request_draft' => $r['request_draft'],
            ];
        }

        return response()->json(['data' => $turn]);
    }

    public function inspiration(Request $request, InspirationBrief $brief, AiGateway $ai): JsonResponse
    {
        $request->validate([
            'image' => ['required', 'file', 'mimetypes:'.implode(',', config('ai.image_mimes')), 'max:'.config('ai.image_max_kb'), 'dimensions:min_width=200,min_height=200,max_width=8000,max_height=8000'],
        ]);

        if (! $ai->available()) {
            return response()->json(['message' => 'Photo reading needs the AI stylist, which is not switched on yet. You can describe the outfit in words instead.', 'code' => 'ai_unavailable'], 503);
        }

        try {
            $result = $brief->analyse($request->file('image'), $request->user(), $request->ip());
        } catch (AiException $e) {
            return $this->aiError($e);
        }

        return response()->json(['data' => $result + ['source' => 'ai']]);
    }

    public function fabricIdeas(Request $request, string $slug, FabricIdeas $ideas): JsonResponse
    {
        $fabric = Fabric::query()->listed()->where('slug', $slug)->firstOrFail();

        return response()->json(['data' => $ideas->for($fabric, $request->user(), $request->ip())]);
    }

    private function aiError(AiException $e): JsonResponse
    {
        return match ($e->kind) {
            'rate_limited' => response()->json(['message' => $e->getMessage(), 'code' => 'ai_rate_limited'], 429),
            'invalid_output' => response()->json(['message' => 'The stylist could not read that photo clearly. Try another photo, or describe the outfit in words.', 'code' => 'ai_invalid_output'], 422),
            default => response()->json(['message' => 'The AI stylist is unavailable right now. Please try again shortly, or describe the outfit in words.', 'code' => 'ai_unavailable'], 503),
        };
    }

    /** @param array<string,mixed> $b */
    private function presentBudget(array $b): array
    {
        $m = fn (?int $minor) => $minor === null ? null : \App\Domain\Money\Money::minor($minor)->toArray();

        return [
            'lines' => array_map(fn ($l) => ['key' => $l['key'], 'label' => $l['label'], 'amount' => $m($l['minor']), 'basis' => $l['basis']], $b['lines']),
            'missing' => $b['missing'],
            'total' => $m($b['total_minor']),
            'budget' => $m($b['budget_minor']),
            'within_budget' => $b['within_budget'],
            'note' => $b['note'],
        ];
    }
}
