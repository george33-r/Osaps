<?php

namespace Tests\Feature\Api;

use App\Models\TailorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/** Sample designs on a request and photos in the customer–tailor chat. */
class DesignPhotosTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_shares_design_photos_and_both_sides_send_chat_photos(): void
    {
        $customer = User::factory()->create();
        $tailor = TailorProfile::factory()->create()->user;
        $stranger = User::factory()->create();
        $id = $this->actingAs($customer)->postJson('/api/v1/job-requests', [
            'title' => 'Lace gown for my sister\'s wedding', 'garment_category' => 'gown',
            'description' => 'Mermaid lace gown with a sheer neckline, like the photo.', 'mode' => 'budget', 'budget_max_minor' => 6_000_000,
            'fabric_status' => 'own_fabric', 'city' => 'Lagos', 'needed_by' => now()->addDays(30)->toDateString(), 'fitting_required' => true,
        ])->assertCreated()->json('data.id');

        $photoId = $this->actingAs($customer)->post("/api/v1/job-requests/{$id}/photos", ['photo' => UploadedFile::fake()->image('design.jpg', 1200, 1600), 'caption' => 'This neckline'], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
        $this->actingAs($stranger)->post("/api/v1/job-requests/{$id}/photos", ['photo' => UploadedFile::fake()->image('x.jpg')], ['Accept' => 'application/json'])->assertForbidden();
        $this->actingAs($customer)->post("/api/v1/job-requests/{$id}/photos", ['photo' => UploadedFile::fake()->create('evil.php', 10, 'text/x-php')], ['Accept' => 'application/json'])->assertStatus(422);

        // The tailor sees it on the request; a non-tailor stranger cannot open the file.
        $this->actingAs($tailor)->getJson("/api/v1/job-requests/{$id}")->assertJsonPath('data.photos.0.caption', 'This neckline');
        $this->actingAs($tailor)->get("/api/v1/design-photos/{$photoId}")->assertOk()->assertHeader('Content-Type', 'image/jpeg');
        $this->actingAs($stranger)->get("/api/v1/design-photos/{$photoId}", ['Accept' => 'application/json'])->assertForbidden();

        $offerId = $this->actingAs($tailor)->postJson("/api/v1/job-requests/{$id}/offers", [
            'amount_minor' => 6_000_000, 'delivery_days' => 14, 'includes_fitting' => true, 'alterations_included' => 1, 'delivery_fee_minor' => 0,
        ])->assertCreated()->json('data.id');
        $msgId = $this->actingAs($tailor)->post("/api/v1/offers/{$offerId}/messages", ['photo' => UploadedFile::fake()->image('sketch.png', 800, 800)], ['Accept' => 'application/json'])
            ->assertCreated()->json('data.id');
        $this->actingAs($customer)->getJson("/api/v1/offers/{$offerId}/messages")->assertJsonPath('data.0.photo_url', url("/api/v1/chat-photos/{$msgId}"));
        $this->actingAs($customer)->get("/api/v1/chat-photos/{$msgId}")->assertOk();
        $this->actingAs($stranger)->get("/api/v1/chat-photos/{$msgId}", ['Accept' => 'application/json'])->assertForbidden();
    }
}
