<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Story;
use App\Models\User;
use App\Services\StoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StoryController extends Controller
{
    /** Internal paths only: "/tailors/x" is allowed, "https://..." and "//host" are not. */
    private const LINK = 'regex:#^/(?!/)[^\s]*$#';

    public function __construct(private readonly StoryService $stories) {}

    /** Public: the latest published stories for the home page. */
    public function index(): JsonResponse
    {
        $stories = Story::query()->published()->with(['author', 'tailorProfile'])
            ->orderByDesc('published_at')->limit(12)->get();

        return response()->json(['data' => $stories->map(fn (Story $s) => $this->present($s, 'public'))->values()]);
    }

    /** The signed-in tailor's own stories, with their status. */
    public function mine(Request $request): JsonResponse
    {
        $stories = Story::query()->where('author_id', $request->user()->id)->with(['author', 'tailorProfile'])->latest('id')->limit(50)->get();

        return response()->json(['data' => $stories->map(fn (Story $s) => $this->present($s, 'owner'))->values()]);
    }

    public function storeMine(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(admin: false));
        $story = $this->stories->create($request->user(), $data, $request->file('photo'));

        return response()->json(['data' => $this->present($story->load(['author', 'tailorProfile']), 'owner')], 201);
    }

    public function updateMine(Request $request, Story $story): JsonResponse
    {
        $data = $request->validate($this->rules(admin: false));
        $story = $this->stories->update($request->user(), $story, $data, $request->file('photo'));

        return response()->json(['data' => $this->present($story->load(['author', 'tailorProfile']), 'owner')]);
    }

    public function destroyMine(Request $request, Story $story): JsonResponse
    {
        $this->stories->delete($request->user(), $story);

        return response()->json(['data' => ['deleted' => true]]);
    }

    // ---- Admin ----------------------------------------------------------------------------

    public function adminIndex(Request $request): JsonResponse
    {
        $status = $request->query('status');
        abort_unless($status === null || in_array($status, [Story::PENDING, Story::PUBLISHED, Story::HIDDEN], true), 422);

        $stories = Story::query()->with(['author', 'tailorProfile'])
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest('id')->limit(200)->get();

        return response()->json(['data' => $stories->map(fn (Story $s) => $this->present($s, 'admin'))->values()]);
    }

    public function adminStore(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(admin: true));
        $story = $this->stories->create($request->user(), $data, $request->file('photo'));

        return response()->json(['data' => $this->present($story->load(['author', 'tailorProfile']), 'admin')], 201);
    }

    public function adminUpdate(Request $request, Story $story): JsonResponse
    {
        $data = $request->validate($this->rules(admin: true));
        $story = $this->stories->update($request->user(), $story, $data, $request->file('photo'));

        return response()->json(['data' => $this->present($story->load(['author', 'tailorProfile']), 'admin')]);
    }

    public function adminStatus(Request $request, Story $story): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in([Story::PUBLISHED, Story::HIDDEN])],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $story = $this->stories->setStatus($request->user(), $story, $data['status'], $data['note'] ?? null);

        return response()->json(['data' => ['id' => $story->id, 'status' => $story->status]]);
    }

    public function adminDestroy(Request $request, Story $story): JsonResponse
    {
        $this->stories->delete($request->user(), $story);

        return response()->json(['data' => ['deleted' => true]]);
    }

    // ---- Helpers --------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function rules(bool $admin): array
    {
        $rules = [
            'title' => ['required', 'string', 'min:3', 'max:80'],
            'body' => ['required', 'string', 'min:10', 'max:600'],
            'link_url' => ['nullable', 'string', 'max:255', self::LINK],
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ];
        if ($admin) {
            $rules['kind'] = ['nullable', Rule::in(Story::KINDS)];
            $rules['tailor_profile_id'] = ['nullable', 'integer', Rule::exists('tailor_profiles', 'id')];
        }

        return $rules;
    }

    /**
     * Public: no email, phone, or review detail. Owner: adds status and the reason if hidden.
     * Admin: adds the decision fields.
     *
     * @return array<string,mixed>
     */
    private function present(Story $s, string $audience): array
    {
        $profile = $s->tailorProfile;
        $author = $s->author;
        $authorIsAdmin = $author?->role === 'admin';
        $name = $profile?->business_name;

        // A tip is credited to the tailor who answered the question.
        $label = $s->kind === 'tip' && $name ? 'Answered by '.$name : ($authorIsAdmin
            ? ($name ? 'Featuring '.$name : 'OSAPS')
            : ($name ? 'By '.$name : 'By an OSAPS tailor'));

        $data = [
            'id' => $s->id,
            'kind' => $s->kind,
            'kind_label' => match ($s->kind) { 'tip' => 'Tip', 'spotlight' => 'Tailor spotlight', default => 'Story' },
            'title' => $s->title,
            'body' => $s->body,
            'link_url' => $s->link_url,
            'photo_url' => $s->photo_path ? url('/'.$s->photo_path) : null,
            'byline' => $label,
            'tailor' => $profile ? ['name' => $profile->business_name, 'slug' => $profile->slug] : null,
            'published_at' => $s->published_at?->toIso8601String(),
        ];

        if ($audience === 'owner' || $audience === 'admin') {
            $data['status'] = $s->status;
            $data['hidden_reason'] = $s->hidden_reason;
        }
        if ($audience === 'admin') {
            $data['author_id'] = $s->author_id;
            $data['author_name'] = $author?->name;
            $data['tailor_profile_id'] = $s->tailor_profile_id;
        }

        return $data;
    }
}
