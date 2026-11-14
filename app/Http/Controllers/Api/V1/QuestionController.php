<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Question;
use App\Services\QuestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Ask a tailor. Public list shows only published answers; everyone else sees what they are allowed to see. */
class QuestionController extends Controller
{
    public function __construct(private readonly QuestionService $questions) {}

    public function published(): JsonResponse
    {
        $items = Question::query()->where('status', Question::PUBLISHED)->with('tailorProfile')
            ->orderByDesc('answered_at')->limit(50)->get();

        return response()->json(['data' => $items->map(fn (Question $q) => [
            'id' => $q->id,
            'question' => $q->question,
            'answer' => $q->answer,
            'answered_by' => $q->tailorProfile ? ['name' => $q->tailorProfile->business_name, 'slug' => $q->tailorProfile->slug] : null,
            'answered_at' => $q->answered_at?->toIso8601String(),
        ])->values()]);
    }

    public function mine(Request $request): JsonResponse
    {
        $items = Question::query()->where('asker_id', $request->user()->id)->latest('id')->limit(50)->get();

        return response()->json(['data' => $items->map(fn (Question $q) => [
            'id' => $q->id,
            'question' => $q->question,
            'status' => $q->status,
            'answer' => $q->status === Question::PUBLISHED ? $q->answer : null,
            'hidden_reason' => $q->hidden_reason,
        ])->values()]);
    }

    public function ask(Request $request): JsonResponse
    {
        $data = $request->validate(['question' => ['required', 'string', 'min:10', 'max:300']]);
        $q = $this->questions->ask($request->user(), $data['question']);

        return response()->json(['data' => ['id' => $q->id, 'status' => $q->status]], 201);
    }

    /** Tailors: questions open for an answer. */
    public function openForTailors(): JsonResponse
    {
        $items = Question::query()->where('status', Question::OPEN)->oldest('id')->limit(50)->get();

        return response()->json(['data' => $items->map(fn (Question $q) => ['id' => $q->id, 'question' => $q->question])->values()]);
    }

    public function answer(Request $request, Question $question): JsonResponse
    {
        $data = $request->validate(['answer' => ['required', 'string', 'min:10', 'max:600']]);
        $q = $this->questions->answer($request->user(), $question, $data['answer']);

        return response()->json(['data' => ['id' => $q->id, 'status' => $q->status]]);
    }

    // ---- Admin ---------------------------------------------------------------------------

    public function adminIndex(Request $request): JsonResponse
    {
        $status = $request->query('status', Question::PENDING);
        abort_unless(in_array($status, [Question::PENDING, Question::OPEN, Question::ANSWERED, Question::PUBLISHED, Question::HIDDEN], true), 422);
        $items = Question::query()->where('status', $status)->with(['asker', 'tailorProfile'])->oldest('id')->limit(200)->get();

        return response()->json(['data' => $items->map(fn (Question $q) => [
            'id' => $q->id,
            'question' => $q->question,
            'status' => $q->status,
            'asker_name' => $q->asker?->name,
            'answer' => $q->answer,
            'answered_by' => $q->tailorProfile?->business_name,
            'tailor_profile_id' => $q->tailor_profile_id,
        ])->values()]);
    }

    public function approve(Request $request, Question $question): JsonResponse
    {
        $q = $this->questions->approve($request->user(), $question);

        return response()->json(['data' => ['id' => $q->id, 'status' => $q->status]]);
    }

    public function hide(Request $request, Question $question): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:300']]);
        $q = $this->questions->hide($request->user(), $question, $data['note']);

        return response()->json(['data' => ['id' => $q->id, 'status' => $q->status]]);
    }

    public function publish(Request $request, Question $question): JsonResponse
    {
        $q = $this->questions->publish($request->user(), $question);

        return response()->json(['data' => ['id' => $q->id, 'status' => $q->status, 'story_id' => $q->story_id]]);
    }
}
