<?php

namespace App\Services;

use App\Domain\Orders\OrderStatus;
use App\Domain\Support\DomainRuleViolation;
use App\Models\AdminAction;
use App\Models\Order;
use App\Models\TailorReview;
use App\Models\TailorReviewAppeal;
use App\Models\TailorReviewPhoto;
use App\Models\TailorReviewReport;
use App\Models\TailorReviewResponse;
use App\Models\TailorReviewRevision;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Customer reviews of tailors.
 *
 * Rules (approved):
 * - Only the customer on a completed, paid order may review it, once (unique order_id in the database).
 * - The review window is 30 days from the order's actual completed_at.
 * - One correction within 24 hours of submission. The original and the corrected text are both kept as revisions.
 * - A tailor's reply is kept for good, even when the customer later corrects the review.
 * - Photos are private until an admin approves them; written reviews publish regardless of photo moderation.
 */
class TailorReviewService
{
    public const REVIEW_WINDOW_DAYS = 30;

    public const CORRECTION_WINDOW_HOURS = 24;

    public const MAX_PHOTOS = 3;

    public const REPORT_REASONS = ['fake', 'abusive', 'personal_information', 'not_my_order', 'other'];

    public function __construct(
        private readonly PrivatePhotoService $photos,
        private readonly Notifier $notifier,
    ) {}

    /** @param array<string,mixed> $content */
    public function submit(User $customer, Order $order, array $content, array $photos = []): TailorReview
    {
        if ($order->customer_id !== $customer->id) {
            throw new AuthorizationException('Only the customer on this order can review it.');
        }

        return DB::transaction(function () use ($customer, $order, $content, $photos) {
            $order = Order::query()->lockForUpdate()->findOrFail($order->id);

            if ($order->status !== OrderStatus::COMPLETED) {
                throw new DomainRuleViolation('You can review a tailor once the order is completed.', 'not_completed');
            }
            if ($order->payment_status !== 'paid') {
                throw new DomainRuleViolation('Reviews are open for paid orders only.', 'not_paid');
            }
            if ($order->completed_at === null || now()->greaterThan($order->completed_at->copy()->addDays(self::REVIEW_WINDOW_DAYS))) {
                throw new DomainRuleViolation('The '.self::REVIEW_WINDOW_DAYS.'-day window to review this order has closed.', 'review_window_closed');
            }
            if (TailorReview::query()->where('order_id', $order->id)->exists()) {
                throw new DomainRuleViolation('You have already reviewed this order.', 'already_reviewed');
            }

            $now = now();
            $review = TailorReview::query()->create(array_merge($this->snapshot($content), [
                'order_id' => $order->id,
                'customer_id' => $customer->id,
                'tailor_id' => $order->tailor_id,
                'status' => TailorReview::PUBLISHED,
                'submitted_at' => $now,
                'correction_count' => 0,
            ]));

            TailorReviewRevision::query()->create(array_merge($this->snapshot($content), [
                'review_id' => $review->id, 'version' => 1, 'created_at' => $now,
            ]));

            foreach (array_slice($photos, 0, self::MAX_PHOTOS) as $file) {
                $this->storePhoto($review, $file);
            }

            $this->notifier->notify($order->tailor_id, 'tailor_review', 'New customer review', 'A customer has reviewed your work.', '/tailor');

            return $review->load('revisions');
        });
    }

    /** @param array<string,mixed> $content */
    public function correct(User $customer, TailorReview $review, array $content): TailorReview
    {
        if ($review->customer_id !== $customer->id) {
            throw new AuthorizationException('Only the reviewer can correct this review.');
        }

        return DB::transaction(function () use ($review, $content) {
            $review = TailorReview::query()->lockForUpdate()->findOrFail($review->id);

            if ($review->status !== TailorReview::PUBLISHED) {
                throw new DomainRuleViolation('This review is not published, so it cannot be corrected.', 'review_hidden');
            }
            if ($review->correction_count >= 1) {
                throw new DomainRuleViolation('A review can be corrected once.', 'already_corrected');
            }
            if ($review->submitted_at->copy()->addHours(self::CORRECTION_WINDOW_HOURS)->lessThan(now())) {
                throw new DomainRuleViolation('Corrections are allowed within '.self::CORRECTION_WINDOW_HOURS.' hours of posting.', 'correction_window_closed');
            }

            $version = (int) TailorReviewRevision::query()->where('review_id', $review->id)->max('version') + 1;
            TailorReviewRevision::query()->create(array_merge($this->snapshot($content), [
                'review_id' => $review->id, 'version' => $version, 'created_at' => now(),
            ]));

            $review->forceFill(array_merge($this->snapshot($content), [
                'corrected_at' => now(), 'correction_count' => 1,
            ]))->save();

            return $review->load('revisions');
        });
    }

    public function respond(User $tailor, TailorReview $review, string $body): TailorReviewResponse
    {
        if ($review->tailor_id !== $tailor->id) {
            throw new AuthorizationException('Only the tailor on this order can reply.');
        }

        return DB::transaction(function () use ($tailor, $review, $body) {
            $review = TailorReview::query()->lockForUpdate()->findOrFail($review->id);

            if ($review->status !== TailorReview::PUBLISHED) {
                throw new DomainRuleViolation('This review is not published.', 'review_hidden');
            }
            if (TailorReviewResponse::query()->where('review_id', $review->id)->exists()) {
                throw new DomainRuleViolation('You have already replied to this review. Replies cannot be removed.', 'already_responded');
            }

            $response = TailorReviewResponse::query()->create([
                'review_id' => $review->id,
                'tailor_id' => $tailor->id,
                'responded_to_version' => $review->correction_count + 1,
                'body' => mb_substr(trim($body), 0, 1000),
                'created_at' => now(),
            ]);

            $this->notifier->notify($review->customer_id, 'tailor_review_reply', 'The tailor replied to your review', null, '/orders');

            return $response;
        });
    }

    public function report(User $reporter, TailorReview $review, string $reason, ?string $details): TailorReviewReport
    {
        if (! in_array($reason, self::REPORT_REASONS, true)) {
            throw new DomainRuleViolation('Choose a reason for the report.', 'bad_reason');
        }
        if (TailorReviewReport::query()->where('review_id', $review->id)->where('reporter_id', $reporter->id)->exists()) {
            throw new DomainRuleViolation('You have already reported this review.', 'already_reported');
        }

        return TailorReviewReport::query()->create([
            'review_id' => $review->id,
            'reporter_id' => $reporter->id,
            'reason' => $reason,
            'details' => $details !== null ? mb_substr(trim($details), 0, 1000) : null,
            'status' => 'open',
        ]);
    }

    /** Admin hides or restores a review. Hiding needs a reason, which is logged and sent to the customer. */
    public function moderate(User $admin, TailorReview $review, string $to, ?string $note): TailorReview
    {
        return DB::transaction(function () use ($admin, $review, $to, $note) {
            $review = TailorReview::query()->lockForUpdate()->findOrFail($review->id);

            if ($to === TailorReview::HIDDEN) {
                $reason = trim((string) $note);
                if (mb_strlen($reason) < 5) {
                    throw new DomainRuleViolation('Say why the review is hidden (at least 5 characters).', 'note_required');
                }
                $review->forceFill(['status' => TailorReview::HIDDEN, 'hidden_reason' => mb_substr($reason, 0, 300)])->save();
                AdminAction::record($admin, 'tailor_review_hidden', $review, $reason);
                $this->notifier->notify($review->customer_id, 'tailor_review', 'Your review was hidden', $reason, '/orders');
            } else {
                $review->forceFill(['status' => TailorReview::PUBLISHED, 'hidden_reason' => null])->save();
                AdminAction::record($admin, 'tailor_review_restored', $review, $note);
            }

            return $review;
        });
    }

    public function decideReport(User $admin, TailorReviewReport $report, string $decision, ?string $note): TailorReviewReport
    {
        if (! in_array($decision, ['upheld', 'dismissed'], true)) {
            throw new DomainRuleViolation('Decision must be upheld or dismissed.', 'bad_decision');
        }

        return DB::transaction(function () use ($admin, $report, $decision, $note) {
            $report = TailorReviewReport::query()->lockForUpdate()->findOrFail($report->id);
            if ($report->status !== 'open') {
                throw new DomainRuleViolation('This report has already been decided.', 'already_decided');
            }

            $report->forceFill([
                'status' => $decision, 'decided_by' => $admin->id, 'decided_at' => now(),
                'decision_note' => $note !== null ? mb_substr(trim($note), 0, 300) : null,
            ])->save();
            AdminAction::record($admin, 'tailor_review_report_'.$decision, $report, $note);

            if ($decision === 'upheld') {
                $review = TailorReview::query()->findOrFail($report->review_id);
                $reason = 'Hidden after a report: '.($note !== null && trim($note) !== '' ? trim($note) : $report->reason);
                if ($review->status === TailorReview::PUBLISHED) {
                    $this->moderate($admin, $review, TailorReview::HIDDEN, $reason);
                }
            }

            return $report;
        });
    }

    public function decidePhoto(User $admin, TailorReviewPhoto $photo, string $decision): TailorReviewPhoto
    {
        if (! in_array($decision, [TailorReviewPhoto::APPROVED, TailorReviewPhoto::REJECTED], true)) {
            throw new DomainRuleViolation('Decision must be approved or rejected.', 'bad_decision');
        }

        return DB::transaction(function () use ($admin, $photo, $decision) {
            $photo = TailorReviewPhoto::query()->lockForUpdate()->findOrFail($photo->id);
            if ($photo->status !== TailorReviewPhoto::PENDING) {
                throw new DomainRuleViolation('This photo has already been decided.', 'already_decided');
            }
            if ($decision === TailorReviewPhoto::APPROVED && TailorReview::query()->whereKey($photo->review_id)->value('status') !== TailorReview::PUBLISHED) {
                throw new DomainRuleViolation('The review is hidden, so its photos cannot be published.', 'review_hidden');
            }

            $photo->forceFill(['status' => $decision, 'decided_by' => $admin->id, 'decided_at' => now()])->save();
            AdminAction::record($admin, 'tailor_review_photo_'.$decision, $photo);

            return $photo;
        });
    }

    public function appeal(User $customer, TailorReview $review, string $reason): TailorReviewAppeal
    {
        if ($review->customer_id !== $customer->id) {
            throw new AuthorizationException('Only the reviewer can appeal this review.');
        }
        if ($review->status !== TailorReview::HIDDEN) {
            throw new DomainRuleViolation('Only a hidden review can be appealed.', 'review_not_hidden');
        }
        if (TailorReviewAppeal::query()->where('review_id', $review->id)->where('status', 'open')->exists()) {
            throw new DomainRuleViolation('An appeal for this review is already waiting for a decision.', 'appeal_open');
        }

        return TailorReviewAppeal::query()->create([
            'review_id' => $review->id,
            'customer_id' => $customer->id,
            'reason' => mb_substr(trim($reason), 0, 1000),
            'status' => 'open',
        ]);
    }

    public function decideAppeal(User $admin, TailorReviewAppeal $appeal, string $decision, ?string $note): TailorReviewAppeal
    {
        if (! in_array($decision, ['restored', 'upheld'], true)) {
            throw new DomainRuleViolation('Decision must be restored or upheld.', 'bad_decision');
        }
        if ($decision === 'upheld' && mb_strlen(trim((string) $note)) < 5) {
            throw new DomainRuleViolation('Say why the appeal is refused (at least 5 characters).', 'note_required');
        }

        return DB::transaction(function () use ($admin, $appeal, $decision, $note) {
            $appeal = TailorReviewAppeal::query()->lockForUpdate()->findOrFail($appeal->id);
            if ($appeal->status !== 'open') {
                throw new DomainRuleViolation('This appeal has already been decided.', 'already_decided');
            }

            $appeal->forceFill([
                'status' => $decision, 'decided_by' => $admin->id, 'decided_at' => now(),
                'decision_note' => $note !== null ? mb_substr(trim($note), 0, 300) : null,
            ])->save();
            AdminAction::record($admin, 'tailor_review_appeal_'.$decision, $appeal, $note);

            if ($decision === 'restored') {
                $review = TailorReview::query()->findOrFail($appeal->review_id);
                $review->forceFill(['status' => TailorReview::PUBLISHED, 'hidden_reason' => null])->save();
            }

            return $appeal;
        });
    }

    /** @return array<string,mixed> */
    private function snapshot(array $content): array
    {
        return [
            'stars' => (int) $content['stars'],
            'quality' => isset($content['quality']) ? (int) $content['quality'] : null,
            'communication' => isset($content['communication']) ? (int) $content['communication'] : null,
            'delivery_time' => isset($content['delivery_time']) ? (int) $content['delivery_time'] : null,
            'value_for_money' => isset($content['value_for_money']) ? (int) $content['value_for_money'] : null,
            'body' => isset($content['body']) && trim((string) $content['body']) !== '' ? mb_substr(trim((string) $content['body']), 0, 1000) : null,
        ];
    }

    private function storePhoto(TailorReview $review, UploadedFile $file): TailorReviewPhoto
    {
        $stored = $this->photos->store($file, 'tailor-reviews/'.$review->id);

        return TailorReviewPhoto::query()->create([
            'review_id' => $review->id,
            'path' => $stored['path'],
            'mime' => $stored['mime'],
            'size_bytes' => $stored['size_bytes'],
            'status' => TailorReviewPhoto::PENDING,
        ]);
    }
}
