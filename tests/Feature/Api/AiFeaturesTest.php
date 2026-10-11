<?php

namespace Tests\Feature\Api;

use App\Ai\AiException;
use App\Ai\AiProvider;
use App\Ai\Providers\FakeProvider;
use App\Models\AiUsage;
use App\Models\Fabric;
use App\Models\TailorProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AiFeaturesTest extends TestCase
{
    use RefreshDatabase;

    private function fake(string|AiException ...$replies): FakeProvider
    {
        $fake = new FakeProvider(...$replies);
        $this->app->instance(AiProvider::class, $fake);
        $this->app->forgetInstance(\App\Ai\AiGateway::class);

        return $fake;
    }

    private function catalogue(): void
    {
        Fabric::factory()->create(['name' => 'Wine Ankara', 'category' => 'ankara', 'primary_color' => 'burgundy', 'colors' => ['burgundy', 'gold'], 'price_minor' => 450_000]);
        Fabric::factory()->create(['name' => 'Navy Ankara', 'category' => 'ankara', 'primary_color' => 'navy', 'colors' => ['navy'], 'price_minor' => 420_000]);
        Fabric::factory()->create(['name' => 'Ivory Lace', 'category' => 'lace', 'primary_color' => 'cream', 'colors' => ['cream'], 'price_minor' => 1_800_000]);
    }

    public function test_search_without_ai_uses_keywords_and_returns_real_rows(): void
    {
        $this->catalogue();
        $res = $this->getJson('/api/v1/ai/fabric-search?q='.urlencode('wine ankara for a wedding guest under 5k'))->assertOk();
        $res->assertJsonPath('data.source', 'keywords');
        $this->assertSame(['Wine Ankara'], array_column($res->json('data.results'), 'name'));
    }

    public function test_search_with_ai_cannot_add_unknown_filters(): void
    {
        $this->catalogue();
        $this->fake('{"categories":["lace","velvet"],"colors":["cream","neon"],"max_price_naira":null,"terms":[],"understood":"Ivory lace"}');
        $res = $this->getJson('/api/v1/ai/fabric-search?q=soft+lace+for+an+evening+dress')->assertOk();
        $res->assertJsonPath('data.source', 'ai');
        $this->assertSame(['lace'], $res->json('data.criteria.categories'));
        $this->assertSame(['Ivory Lace'], array_column($res->json('data.results'), 'name'));
        $this->assertSame(1, AiUsage::query()->where('outcome', 'ok')->count());
    }

    public function test_search_falls_back_when_ai_output_is_malformed(): void
    {
        $this->catalogue();
        $this->fake('I think you want lace!');
        $res = $this->getJson('/api/v1/ai/fabric-search?q=navy+ankara')->assertOk();
        $res->assertJsonPath('data.source', 'keywords');
        $this->assertSame(['Navy Ankara'], array_column($res->json('data.results'), 'name'));
        $this->assertSame(1, AiUsage::query()->where('outcome', 'invalid_output')->count());
    }

    public function test_search_relaxes_when_nothing_matches_instead_of_inventing(): void
    {
        $this->catalogue();
        $res = $this->getJson('/api/v1/ai/fabric-search?q='.urlencode('purple lace'))->assertOk();
        $this->assertSame(['colour'], $res->json('data.relaxed'));
        $this->assertSame(['Ivory Lace'], array_column($res->json('data.results'), 'name'));
    }

    public function test_stylist_asks_one_question_at_a_time(): void
    {
        $this->postJson('/api/v1/ai/stylist', ['known' => []])->assertOk()
            ->assertJsonPath('data.next_question.slot', 'occasion')
            ->assertJsonPath('data.result', null);
        $this->postJson('/api/v1/ai/stylist', ['known' => ['occasion' => 'wedding_guest']])->assertOk()
            ->assertJsonPath('data.next_question.slot', 'garment');
    }

    public function test_stylist_concept_only_recommends_real_fabrics_and_tailors(): void
    {
        $this->catalogue();
        TailorProfile::factory()->create(['specialties' => ['gown'], 'starting_price_minor' => 2_500_000]);
        $ids = Fabric::query()->pluck('id')->all();
        $this->fake(json_encode([
            'title' => 'Wine ankara gown', 'summary' => 'A sculpted gown costing ₦45,000, guaranteed.', 'silhouette' => 'Fitted bodice, flared hem',
            'colour_direction' => 'Wine with gold', 'fabric_characteristics' => 'Crisp wax print', 'finishing_details' => 'Invisible zip',
            'fabric_ids' => [$ids[0]], // a real id; an invented one would fail schema validation (enum of offered ids)
        ]));

        $res = $this->postJson('/api/v1/ai/stylist', ['known' => [
            'occasion' => 'wedding_guest', 'garment' => 'gown', 'colors' => ['burgundy'], 'budget_naira' => 60_000, 'needs' => 'fabric_and_tailoring', 'timing' => 'month',
        ]])->assertOk();

        $res->assertJsonPath('data.result.source', 'ai');
        $this->assertStringNotContainsString('45,000', $res->json('data.result.concept.summary'));
        $this->assertStringNotContainsString('guarantee', strtolower($res->json('data.result.concept.summary')));
        foreach ($res->json('data.result.fabrics') as $f) {
            $this->assertContains($f['id'], $ids);
        }
        $this->assertNotEmpty($res->json('data.result.tailors'));
        $this->assertSame('budget', $res->json('data.result.request_draft.mode'));
        $this->assertNotNull($res->json('data.result.budget.total'));
    }

    public function test_stylist_rejects_invented_fabric_ids_and_falls_back(): void
    {
        $this->catalogue();
        $this->fake(json_encode([
            'title' => 'x', 'summary' => 'y', 'silhouette' => 'z', 'colour_direction' => 'c', 'fabric_characteristics' => 'f', 'finishing_details' => 'd',
            'fabric_ids' => [987654],
        ]));
        $res = $this->postJson('/api/v1/ai/stylist', ['known' => [
            'occasion' => 'party', 'garment' => 'gown', 'colors' => [], 'colors_open' => true, 'budget_naira' => 0, 'needs' => 'fabric_and_tailoring', 'timing' => 'no_rush',
        ]])->assertOk();
        $res->assertJsonPath('data.result.source', 'guided');
        foreach ($res->json('data.result.fabrics') as $f) {
            $this->assertNotSame(987654, $f['id']);
        }
    }

    public function test_provider_outage_never_breaks_the_stylist(): void
    {
        $this->catalogue();
        $this->fake(new AiException('timeout'));
        $this->postJson('/api/v1/ai/stylist', ['known' => [], 'message' => 'I need a navy senator for my brother\'s wedding', 'slot' => 'occasion'])
            ->assertOk()
            ->assertJsonPath('data.known.occasion', 'wedding_guest');
    }

    public function test_inspiration_requires_sign_in_and_never_stores_the_image(): void
    {
        Storage::fake('local');
        $file = UploadedFile::fake()->image('look.jpg', 600, 800);
        $this->postJson('/api/v1/ai/inspiration', ['image' => $file])->assertStatus(401);

        $user = User::factory()->create();
        $this->fake('{"garment_category":"gown","title":"Off-shoulder gown","summary":"A fitted gown.","confidence":"medium","colors":["gold"]}');
        $this->actingAs($user)->postJson('/api/v1/ai/inspiration', ['image' => $file])->assertOk()
            ->assertJsonPath('data.garment_category', 'gown')
            ->assertJsonPath('data.source', 'ai');
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_inspiration_rejects_non_images_and_reports_unavailable_without_ai(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/api/v1/ai/inspiration', ['image' => UploadedFile::fake()->create('notes.pdf', 50, 'application/pdf')])
            ->assertStatus(422);
        $this->actingAs($user)->postJson('/api/v1/ai/inspiration', ['image' => UploadedFile::fake()->image('look.jpg', 600, 800)])
            ->assertStatus(503)->assertJsonPath('code', 'ai_unavailable');
    }

    public function test_daily_ai_quota_is_enforced(): void
    {
        config(['ai.per_minute' => 100, 'ai.daily_requests_per_ip' => 1]);
        $this->catalogue();
        $this->fake('{"categories":[],"colors":[],"understood":"a"}', '{"categories":[],"colors":[],"understood":"b"}');
        $this->getJson('/api/v1/ai/fabric-search?q=first+query')->assertJsonPath('data.source', 'ai');
        $this->getJson('/api/v1/ai/fabric-search?q=second+query')->assertJsonPath('data.source', 'keywords')
            ->assertJsonPath('data.notice', 'You have used AI assistance a lot today. Please try again later.');
    }
}
