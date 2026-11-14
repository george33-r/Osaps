<?php

namespace Tests\Feature\Api;

use App\Models\Order;
use App\Models\TailorProfile;
use App\Models\TailorReview;
use App\Models\TailorReviewPhoto;
use App\Models\User;
use App\Payments\FakeGateway;
use App\Payments\PaymentGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Tailor reviews: who may review, the windows, corrections, replies, photos, reports and appeals. */
class TailorReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $customer;

    private User $tailor;

    private User $admin;

    private string $ref;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(PaymentGateway::class, new FakeGateway('sk_test_fake'));
        $this->customer = User::factory()->create(['name' => 'Ada Okafor']);
        $this->tailor = TailorProfile::factory()->create()->user;
        $this->admin = User::factory()->admin()->create();

        $job = $this->actingAs($this->customer)->postJson('/api/v1/job-requests', [
            'title' => 'Navy senator', 'garment_category' => 'senator',
            'description' => 'Classic senator with subtle gold embroidery on the placket and cuffs.',
            'mode' => 'budget', 'budget_max_minor' => 3_000_000, 'fabric_status' => 'own_fabric',
            'city' => 'Abuja', 'needed_by' => now()->addDays(21)->toDateString(), 'fitting_required' => true,
        ])->assertCreated();
        $offer = $this->actingAs($this->tailor)->postJson('/api/v1/job-requests/'.$job->json('data.id').'/offers', [
            'amount_minor' => 3_000_000, 'delivery_days' => 10, 'includes_fitting' => true, 'alterations_included' => 1, 'delivery_fee_minor' => 0,
        ])->assertCreated();
        $this->ref = $this->actingAs($this->customer)->postJson('/api/v1/offers/'.$offer->json('data.id').'/accept', $this->acceptPayload($offer->json('data.id')))
            ->assertCreated()->json('data.reference');
    }

    /** Puts the order in the state a finished, paid job is in. */
    private function complete(?\DateTimeInterface $at = null): void
    {
        Order::query()->where('reference', $this->ref)->update([
            'status' => 'completed', 'payment_status' => 'paid', 'completed_at' => $at ?? now(),
        ]);
    }

    private function order(): Order
    {
        return Order::query()->where('reference', $this->ref)->firstOrFail();
    }

    private function review(array $overrides = []): array
    {
        return array_merge(['stars' => 5, 'quality' => 5, 'communication' => 4, 'delivery_time' => 5, 'value_for_money' => 4, 'body' => 'Neat stitching and on time.'], $overrides);
    }

    private function postReview(array $overrides = [])
    {
        return $this->actingAs($this->customer)->postJson("/api/v1/orders/{$this->ref}/review", $this->review($overrides));
    }

    public function test_buyer_reviews_a_completed_paid_order_once(): void
    {
        $this->complete();
        $this->postReview()->assertCreated()->assertJsonPath('data.reviewer_label', 'Verified buyer · A.');
        $this->postReview()->assertStatus(422)->assertJsonPath('code', 'already_reviewed');

        $this->assertDatabaseCount('tailor_reviews', 1);
        $this->assertDatabaseHas('tailor_review_revisions', ['version' => 1, 'stars' => 5]);
    }

    public function test_only_the_customer_on_the_order_can_review(): void
    {
        $this->complete();
        $this->actingAs($this->tailor)->postJson("/api/v1/orders/{$this->ref}/review", $this->review())->assertForbidden();
        $this->assertDatabaseCount('tailor_reviews', 0);
    }

    public function test_order_must_be_completed_and_paid(): void
    {
        $this->postReview()->assertStatus(422)->assertJsonPath('code', 'not_completed');

        Order::query()->where('reference', $this->ref)->update(['status' => 'completed', 'payment_status' => 'unpaid', 'completed_at' => now()]);
        $this->postReview()->assertStatus(422)->assertJsonPath('code', 'not_paid');
    }

    public function test_window_is_thirty_days_from_completed_at(): void
    {
        $this->complete(now()->subDays(31));
        $this->postReview()->assertStatus(422)->assertJsonPath('code', 'review_window_closed');

        $this->complete(now()->subDays(29));
        $this->postReview()->assertCreated();
    }

    public function test_one_correction_within_24_hours_keeps_the_original(): void
    {
        $this->complete();
        $id = $this->postReview()->assertCreated()->json('data.id');

        $this->actingAs($this->customer)->postJson("/api/v1/tailor-reviews/{$id}/correct", $this->review(['stars' => 3, 'body' => 'Fitting was late, edited after reflection.']))
            ->assertOk()->assertJsonPath('data.corrected', true);

        $this->assertDatabaseHas('tailor_review_revisions', ['review_id' => $id, 'version' => 1, 'stars' => 5]);
        $this->assertDatabaseHas('tailor_review_revisions', ['review_id' => $id, 'version' => 2, 'stars' => 3]);
        $this->actingAs($this->customer)->postJson("/api/v1/tailor-reviews/{$id}/correct", $this->review())
            ->assertStatus(422)->assertJsonPath('code', 'already_corrected');
    }

    public function test_correction_is_refused_after_24_hours(): void
    {
        $this->complete();
        $id = $this->postReview()->assertCreated()->json('data.id');
        $this->travel(25)->hours();

        $this->actingAs($this->customer)->postJson("/api/v1/tailor-reviews/{$id}/correct", $this->review(['stars' => 2]))
            ->assertStatus(422)->assertJsonPath('code', 'correction_window_closed');
    }

    public function test_tailor_reply_is_kept_when_the_review_is_corrected(): void
    {
        $this->complete();
        $id = $this->postReview()->assertCreated()->json('data.id');

        $this->actingAs($this->tailor)->postJson("/api/v1/tailor-reviews/{$id}/respond", ['body' => 'Thank you, the fitting was rescheduled by you.'])
            ->assertCreated();
        $this->actingAs($this->customer)->postJson("/api/v1/tailor-reviews/{$id}/correct", $this->review(['stars' => 4]))->assertOk();

        $this->assertDatabaseHas('tailor_review_responses', ['review_id' => $id, 'responded_to_version' => 1]);
        $this->actingAs($this->tailor)->postJson("/api/v1/tailor-reviews/{$id}/respond", ['body' => 'A second reply that should be refused.'])
            ->assertStatus(422)->assertJsonPath('code', 'already_responded');
    }

    public function test_customer_cannot_see_their_measurements_or_order_reference_publicly(): void
    {
        $this->complete();
        $this->postReview()->assertCreated();

        $slug = TailorProfile::query()->where('user_id', $this->tailor->id)->value('slug');
        $body = $this->getJson("/api/v1/tailors/{$slug}/reviews")->assertOk()->json();
        $raw = json_encode($body);

        $this->assertStringNotContainsString($this->ref, $raw);
        $this->assertStringNotContainsString($this->customer->email, $raw);
        $this->assertSame(1, $body['data']['summary']['count']);
    }

    public function test_photos_are_hidden_until_an_admin_approves_them_and_the_review_stays_visible(): void
    {
        $this->complete();
        $this->actingAs($this->customer)->postJson("/api/v1/orders/{$this->ref}/review", $this->review([
            'photos' => [UploadedFile::fake()->image('outfit.jpg', 400, 400)],
        ]))->assertCreated();

        $photo = TailorReviewPhoto::query()->firstOrFail();
        $this->assertSame('pending', $photo->status);
        $this->get("/api/v1/tailor-review-photos/{$photo->id}")->assertNotFound();

        $this->actingAs($this->admin)->postJson("/api/v1/admin/tailor-review-photos/{$photo->id}/decide", ['decision' => 'approved'])->assertOk();
        $this->get("/api/v1/tailor-review-photos/{$photo->id}")->assertOk();

        $slug = TailorProfile::query()->where('user_id', $this->tailor->id)->value('slug');
        $this->getJson("/api/v1/tailors/{$slug}/reviews")->assertOk()->assertJsonCount(1, 'data.reviews');
    }

    public function test_hidden_reviews_are_removed_from_public_list_and_can_be_appealed(): void
    {
        $this->complete();
        $id = $this->postReview()->assertCreated()->json('data.id');
        $slug = TailorProfile::query()->where('user_id', $this->tailor->id)->value('slug');

        $this->actingAs($this->admin)->postJson("/api/v1/admin/tailor-reviews/{$id}/moderate", ['to' => 'hidden'])
            ->assertStatus(422)->assertJsonPath('code', 'note_required');
        $this->actingAs($this->admin)->postJson("/api/v1/admin/tailor-reviews/{$id}/moderate", ['to' => 'hidden', 'note' => 'Contains a phone number.'])->assertOk();
        $this->getJson("/api/v1/tailors/{$slug}/reviews")->assertJsonCount(0, 'data.reviews');

        $this->actingAs($this->customer)->postJson("/api/v1/tailor-reviews/{$id}/appeal", ['reason' => 'I removed the number, please restore it.'])->assertCreated();
        $this->actingAs($this->customer)->postJson("/api/v1/tailor-reviews/{$id}/appeal", ['reason' => 'Trying again while one is open.'])
            ->assertStatus(422)->assertJsonPath('code', 'appeal_open');

        $appealId = \App\Models\TailorReviewAppeal::query()->value('id');
        $this->actingAs($this->admin)->postJson("/api/v1/admin/tailor-review-appeals/{$appealId}/decide", ['decision' => 'restored'])->assertOk();
        $this->getJson("/api/v1/tailors/{$slug}/reviews")->assertJsonCount(1, 'data.reviews');
    }

    public function test_upheld_report_hides_the_review_and_each_person_reports_once(): void
    {
        $this->complete();
        $id = $this->postReview()->assertCreated()->json('data.id');

        $this->actingAs($this->tailor)->postJson("/api/v1/tailor-reviews/{$id}/report", ['reason' => 'abusive'])->assertCreated();
        $this->actingAs($this->tailor)->postJson("/api/v1/tailor-reviews/{$id}/report", ['reason' => 'abusive'])
            ->assertStatus(422)->assertJsonPath('code', 'already_reported');

        $reportId = \App\Models\TailorReviewReport::query()->value('id');
        $this->actingAs($this->admin)->postJson("/api/v1/admin/tailor-review-reports/{$reportId}/decide", ['decision' => 'upheld', 'note' => 'Insulting language confirmed.'])->assertOk();

        $this->assertSame(TailorReview::HIDDEN, TailorReview::query()->findOrFail($id)->status);
    }

    public function test_tailor_sees_only_published_reviews_and_summary_counts_them(): void
    {
        $this->complete();
        $this->postReview(['stars' => 4])->assertCreated();

        $this->actingAs($this->tailor)->getJson('/api/v1/me/tailor-reviews')
            ->assertOk()->assertJsonPath('data.summary.count', 1)->assertJsonPath('data.summary.average', 4)
            ->assertJsonCount(1, 'data.reviews');
    }
}
