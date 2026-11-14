<?php

namespace Tests\Unit\Domain;

use App\Domain\Matching\TailorScorer;
use PHPUnit\Framework\TestCase;

/** Reviews change matching: unrated tailors are not penalised, poorly rated tailors are labelled as such. */
final class TailorRatingScoringTest extends TestCase
{
    private function job(): array
    {
        return ['garment_category' => 'senator', 'city' => 'abuja', 'style_tags' => []];
    }

    private function tailor(array $overrides = []): array
    {
        return array_merge([
            'specialties' => ['senator'], 'service_cities' => ['abuja'], 'portfolio_tags' => [],
            'completed_orders' => 12, 'on_time_orders' => 12, 'upheld_complaints' => 0,
            'open_orders' => 0, 'weekly_capacity' => 5, 'median_response_minutes' => 30,
        ], $overrides);
    }

    public function test_unrated_tailor_has_no_rating_factor_and_is_not_penalised(): void
    {
        $unrated = (new TailorScorer)->score($this->job(), $this->tailor(['review_count' => 0, 'average_stars' => null]));
        $noField = (new TailorScorer)->score($this->job(), $this->tailor());

        $this->assertArrayNotHasKey('rating', $unrated['factors']);
        $this->assertContains('Not yet rated by customers.', $unrated['reasons']);
        $this->assertSame($noField['score'], $unrated['score']);
        $this->assertGreaterThan(85, $unrated['score']);
    }

    public function test_well_rated_tailor_scores_above_unrated_with_the_same_record(): void
    {
        $unrated = (new TailorScorer)->score($this->job(), $this->tailor());
        $rated = (new TailorScorer)->score($this->job(), $this->tailor(['review_count' => 20, 'average_stars' => 4.8]));

        $this->assertGreaterThan($unrated['score'], $rated['score']);
        $this->assertArrayHasKey('rating', $rated['factors']);
    }

    public function test_poorly_rated_tailor_is_labelled_differently_from_unrated(): void
    {
        $poor = (new TailorScorer)->score($this->job(), $this->tailor(['review_count' => 12, 'average_stars' => 1.8]));
        $unrated = (new TailorScorer)->score($this->job(), $this->tailor(['review_count' => 0, 'average_stars' => null]));

        $this->assertContains('Lower customer ratings so far — read the reviews before choosing.', $poor['reasons']);
        $this->assertNotContains('Not yet rated by customers.', $poor['reasons']);
        $this->assertLessThan($unrated['score'], $poor['score']);
    }

    public function test_one_review_is_shrunk_toward_neutral(): void
    {
        $one = (new TailorScorer)->score($this->job(), $this->tailor(['review_count' => 1, 'average_stars' => 5.0]));
        $many = (new TailorScorer)->score($this->job(), $this->tailor(['review_count' => 50, 'average_stars' => 5.0]));

        $this->assertLessThan($many['factors']['rating'], $one['factors']['rating']);
    }
}
