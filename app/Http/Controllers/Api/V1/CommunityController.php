<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Moment;
use App\Models\Question;
use App\Models\Story;
use App\Models\StorySave;
use App\Models\TailorFeature;
use App\Models\TailorProfile;
use App\Models\User;
use App\Services\MomentService;
use App\Services\QuestionService;
use App\Services\TailorFeatureService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Saved stories, tailor of the week, ask-a-tailor questions and moments. */
class CommunityController extends Controller
{
    public function __construct(
        private readonly MomentService $moments,
        private readonly QuestionService $questions,
        private readonly TailorFeatureService $features,
    ) {}

    // ---- Saved stories -------------------------------------------------------------------

    public function savedStories(Request $request): JsonResponse
    {
        $saves = StorySave::query()->where('user_id', $request->user()->id)->with('story.author', 'story.tailorProfile')
            ->latest('id')->limit(100)->get();
        $stories = $saves->pluck('story')->filter(fn ($s) => $s && $s->status === Story::PUBLISHED);

        return response()->json(['data' => $stories->map(fn (Story $s) => $this->storyCard($s))->values()]);
    }

    public function saveStory(Request $request, Story $story): JsonResponse
    {
        abort_unless($story->status === Story::PUBLISHED, 404);
        StorySave::query()->firstOrCreate(['user_id' => $request->user()->id, 'story_id' => $story->id]);

        return response()->json(['data' => ['saved' => true]], 201);
    }

    public function unsaveStory(Request $request, Story $story): JsonResponse
    {
        StorySave::query()->where('user_id', $request->user()->id)->where('story_id', $story->id)->delete();

        return response()->json(['data' => ['saved' => false]]);
    }

    /** The ids of stories this person has saved, so the home page can show which are saved. */
    public function savedIds(Request $request): JsonResponse
    {
        return response()->json(['data' => StorySave::query()->where('user_id', $request->user()->id)->pluck('story_id')->values()]);
    }

    // ---- Tailor of the week --------------------------------------------------------------

    public function tailorOfWeek(): JsonResponse
    {
        $row = $this->features->current();
        if ($row === null) {
            return response()->json(['data' => null]);
        }
        $p = $row->tailorProfile;
        $summary = $p->reviewSummary();
        $record = $p->trackRecord();

        return response()->json(['data' => [
            'week_start' => $row->week_start->toDateString(),
            'source_label' => $row->source === TailorFeature::ADMIN ? 'Chosen by the OSAPS team' : 'Top rated and on time this week',
            'tailor' => [
                'name' => $p->business_name,
                'slug' => $p->slug,
                'city' => $p->city,
                'photo_url' => $p->profile_photo_path ? url('/'.$p->profile_photo_path) : null,
                'average' => $summary['average'],
                'review_count' => $summary['count'],
                'completed_orders' => $record['completed_orders'],
                'on_time_rate' => $record['completed_orders'] > 0 ? round($record['on_time_orders'] / $record['completed_orders'], 2) : null,
            ],
        ]]);
    }

    public function setTailorOfWeek(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tailor_profile_id' => ['required', 'integer', Rule::exists('tailor_profiles', 'id')],
            'reason' => ['required', 'string', 'min:10', 'max:300'],
        ]);
        $row = $this->features->override($request->user(), TailorProfile::query()->findOrFail($data['tailor_profile_id']), $data['reason']);

        return response()->json(['data' => ['tailor' => $row->tailorProfile->business_name, 'source' => $row->source]]);
    }

    // ---- Moments -------------------------------------------------------------------------

    /** Public: one circle per person, with their live moments, newest first. */
    public function rings(): JsonResponse
    {
        $live = Moment::query()->live()->with(['author', 'tailorProfile'])->orderByDesc('published_at')->limit(120)->get();
        $rings = $live->groupBy('author_id')->map(function ($items) {
            /** @var Moment $first */
            $first = $items->first();
            $p = $first->tailorProfile;

            return [
                'author_id' => $first->author_id,
                'label' => $p ? $p->business_name : 'Customer',
                'tailor' => $p ? ['name' => $p->business_name, 'slug' => $p->slug] : null,
                'avatar_url' => $p?->profile_photo_path ? url('/'.$p->profile_photo_path) : null,
                'latest_at' => $first->published_at?->toIso8601String(),
                'moments' => $items->map(fn (Moment $m) => $this->momentCard($m))->values(),
            ];
        })->sortByDesc('latest_at')->values();

        return response()->json(['data' => $rings]);
    }

    public function myMoments(Request $request): JsonResponse
    {
        $items = Moment::query()->where('author_id', $request->user()->id)->latest('id')->limit(50)->get();

        return response()->json(['data' => $items->map(fn (Moment $m) => $this->momentCard($m, owner: true))->values()]);
    }

    public function postMoment(Request $request): JsonResponse
    {
        $data = $request->validate([
            'media' => ['required', 'file', 'mimetypes:image/jpeg,image/png,image/webp,video/mp4,video/webm,video/quicktime', 'max:524288'],
            'caption' => ['nullable', 'string', 'max:140'],
        ]);
        $m = $this->moments->post($request->user(), $request->file('media'), $data['caption'] ?? null);

        return response()->json(['data' => $this->momentCard($m, owner: true)], 201);
    }

    public function deleteMoment(Request $request, Moment $moment): JsonResponse
    {
        $this->moments->delete($request->user(), $moment);

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function adminMoments(Request $request): JsonResponse
    {
        $status = $request->query('status', Moment::PENDING);
        abort_unless(in_array($status, [Moment::PENDING, Moment::PUBLISHED, Moment::HIDDEN], true), 422);
        $items = Moment::query()->where('status', $status)->with('author')->oldest('id')->limit(200)->get();

        return response()->json(['data' => $items->map(fn (Moment $m) => $this->momentCard($m, owner: true, admin: true))->values()]);
    }

    public function decideMoment(Request $request, Moment $moment): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([Moment::PUBLISHED, Moment::HIDDEN])],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $m = $this->moments->decide($request->user(), $moment, $data['status'], $data['note'] ?? null);

        return response()->json(['data' => ['id' => $m->id, 'status' => $m->status]]);
    }

    // ---- Presentation --------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function momentCard(Moment $m, bool $owner = false, bool $admin = false): array
    {
        $data = [
            'id' => $m->id,
            'type' => $m->media_type,
            'url' => url('/'.$m->media_path),
            'caption' => $m->caption,
            'posted_at' => $m->created_at?->toIso8601String(),
            'published_at' => $m->published_at?->toIso8601String(),
            'expires_at' => $m->published_at?->copy()->addHours(Moment::LIFETIME_HOURS)->toIso8601String(),
        ];
        if ($owner) {
            $data['status'] = $m->status;
            $data['hidden_reason'] = $m->hidden_reason;
        }
        if ($admin) {
            $data['author_name'] = $m->author?->name;
            $data['author_id'] = $m->author_id;
        }

        return $data;
    }

    /** @return array<string,mixed> */
    private function storyCard(Story $s): array
    {
        return [
            'id' => $s->id,
            'kind' => $s->kind,
            'kind_label' => match ($s->kind) { 'tip' => 'Tip', 'spotlight' => 'Tailor spotlight', default => 'Story' },
            'title' => $s->title,
            'body' => $s->body,
            'link_url' => $s->link_url,
            'photo_url' => $s->photo_path ? url('/'.$s->photo_path) : null,
            'tailor' => $s->tailorProfile ? ['name' => $s->tailorProfile->business_name, 'slug' => $s->tailorProfile->slug] : null,
            'published_at' => $s->published_at?->toIso8601String(),
        ];
    }
}
