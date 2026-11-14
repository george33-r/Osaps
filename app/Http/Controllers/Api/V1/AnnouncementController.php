<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use App\Models\Story;
use App\Models\User;
use App\Services\AnnouncementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class AnnouncementController extends Controller
{
    public function __construct(private readonly AnnouncementService $announcements) {}

    /** Admin: how many people would receive it, before anything is sent. */
    public function audience(Request $request): JsonResponse
    {
        $data = $request->validate(['audience' => ['required', Rule::in(Announcement::AUDIENCES)]]);

        return response()->json(['data' => ['audience' => $data['audience'], 'recipients' => $this->announcements->audience($data['audience'])->count()]]);
    }

    public function index(): JsonResponse
    {
        $items = Announcement::query()->with('creator:id,name')->latest('id')->limit(30)->get();

        return response()->json(['data' => $items->map(fn (Announcement $a) => [
            'id' => $a->id,
            'audience' => $a->audience,
            'subject' => $a->subject,
            'recipients' => $a->recipients,
            'sent_at' => $a->sent_at?->toIso8601String(),
            'sent_by' => $a->creator?->name,
        ])->values()]);
    }

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'audience' => ['required', Rule::in(Announcement::AUDIENCES)],
            'subject' => ['required', 'string', 'min:3', 'max:150'],
            'body' => ['required', 'string', 'min:10', 'max:3000'],
            'story_id' => ['nullable', 'integer', Rule::exists('stories', 'id')->where('status', Story::PUBLISHED)],
        ]);
        $story = isset($data['story_id']) ? Story::query()->find($data['story_id']) : null;
        $a = $this->announcements->send($request->user(), $data['audience'], $data['subject'], $data['body'], $story);

        return response()->json(['data' => ['id' => $a->id, 'recipients' => $a->recipients]], 202);
    }

    /** Public, but only through a signed link from the email. Turns email updates off for that person. */
    public function unsubscribe(User $user): Response
    {
        $user->forceFill(['email_updates_opt_in' => false])->save();

        return response('<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>OSAPS</title></head><body style="font-family:sans-serif;padding:24px;line-height:1.5"><h1>You are unsubscribed</h1><p>You will not get OSAPS updates by email. You can turn them back on in your account at any time.</p></body></html>')
            ->header('Content-Type', 'text/html; charset=utf-8');
    }
}
