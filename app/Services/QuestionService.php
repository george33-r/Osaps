<?php

namespace App\Services;

use App\Domain\Support\DomainRuleViolation;
use App\Models\AdminAction;
use App\Models\Question;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Ask a tailor. Flow: customer asks -> admin checks -> open to tailors -> a verified tailor answers
 * -> admin publishes the answer as a tip on the home page.
 */
class QuestionService
{
    public const MAX_WAITING_PER_ASKER = 5;

    public function __construct(private readonly StoryService $stories) {}

    public function ask(User $asker, string $text): Question
    {
        $waiting = Question::query()->where('asker_id', $asker->id)->where('status', Question::PENDING)->count();
        if ($waiting >= self::MAX_WAITING_PER_ASKER) {
            throw new DomainRuleViolation('You have '.$waiting.' questions waiting for a check. Wait for a reply before asking more.', 'too_many_pending');
        }

        return Question::query()->create([
            'asker_id' => $asker->id,
            'question' => mb_substr(trim($text), 0, 300),
            'status' => Question::PENDING,
        ]);
    }

    public function approve(User $admin, Question $q): Question
    {
        return $this->transition($q, Question::PENDING, Question::OPEN, $admin, 'question_approved');
    }

    public function hide(User $admin, Question $q, string $note): Question
    {
        if (mb_strlen(trim($note)) < 5) {
            throw new DomainRuleViolation('Say why the question is hidden (at least 5 characters).', 'note_required');
        }
        if (in_array($q->status, [Question::PUBLISHED], true)) {
            throw new DomainRuleViolation('A published answer cannot be hidden here. Hide the tip on the home page instead.', 'already_published');
        }

        return DB::transaction(function () use ($admin, $q, $note) {
            $q = Question::query()->lockForUpdate()->findOrFail($q->id);
            $q->forceFill(['status' => Question::HIDDEN, 'hidden_reason' => mb_substr(trim($note), 0, 300), 'decided_by' => $admin->id])->save();
            AdminAction::record($admin, 'question_hidden', $q, $note);

            return $q;
        });
    }

    public function answer(User $tailor, Question $q, string $body): Question
    {
        $profile = $tailor->tailorProfile ?? throw new DomainRuleViolation('Set up your tailor profile before answering.', 'profile_missing');
        if (! $profile->isVerified()) {
            throw new DomainRuleViolation('Only verified tailors can answer customer questions.', 'verification_required');
        }

        return DB::transaction(function () use ($profile, $q, $body) {
            $q = Question::query()->lockForUpdate()->findOrFail($q->id);
            if ($q->status !== Question::OPEN) {
                throw new DomainRuleViolation('This question is no longer open for answers.', 'not_open');
            }
            $q->forceFill([
                'status' => Question::ANSWERED,
                'tailor_profile_id' => $profile->id,
                'answer' => mb_substr(trim($body), 0, 600),
                'answered_at' => now(),
            ])->save();

            return $q;
        });
    }

    /** Publishing an answer creates a tip on the home page, written by the admin and credited to the tailor. */
    public function publish(User $admin, Question $q): Question
    {
        return DB::transaction(function () use ($admin, $q) {
            $q = Question::query()->lockForUpdate()->findOrFail($q->id);
            if ($q->status !== Question::ANSWERED) {
                throw new DomainRuleViolation('Only an answer waiting for approval can be published.', 'not_answered');
            }
            $story = $this->stories->create($admin, [
                'kind' => 'tip',
                'title' => mb_substr($q->question, 0, 80),
                'body' => $q->answer,
                'tailor_profile_id' => $q->tailor_profile_id,
            ]);
            $q->forceFill(['status' => Question::PUBLISHED, 'story_id' => $story->id, 'decided_by' => $admin->id])->save();
            AdminAction::record($admin, 'question_published', $q);

            return $q;
        });
    }

    private function transition(Question $q, string $from, string $to, User $admin, string $action): Question
    {
        return DB::transaction(function () use ($q, $from, $to, $admin, $action) {
            $q = Question::query()->lockForUpdate()->findOrFail($q->id);
            if ($q->status !== $from) {
                throw new DomainRuleViolation('This question has already been decided.', 'already_decided');
            }
            $q->forceFill(['status' => $to, 'decided_by' => $admin->id])->save();
            AdminAction::record($admin, $action, $q);

            return $q;
        });
    }
}
