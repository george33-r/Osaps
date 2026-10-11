<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Matching\TailorScorer;
use App\Domain\Quotes\QuoteComparison;
use PHPUnit\Framework\TestCase;

final class MatchingAndQuotesTest extends TestCase
{
    private function tailor(array $overrides = []): array
    {
        return array_merge([
            'specialties' => ['senator'],
            'service_cities' => ['Abuja'],
            'portfolio_tags' => ['embroidered'],
            'completed_orders' => 40,
            'on_time_orders' => 38,
            'upheld_complaints' => 0,
            'open_orders' => 2,
            'weekly_capacity' => 8,
            'median_response_minutes' => 25,
        ], $overrides);
    }

    public function test_relevant_local_reliable_tailor_scores_high_with_reasons(): void
    {
        $r = (new TailorScorer)->score(['garment_category' => 'senator', 'city' => 'abuja', 'style_tags' => ['embroidered']], $this->tailor());
        $this->assertGreaterThan(85, $r['score']);
        $this->assertFalse($r['new_vendor']);
        $this->assertContains('Specialises in senator.', $r['reasons']);
        $this->assertContains('Serves Abuja.', $r['reasons']);
    }

    public function test_specialty_and_location_mismatch_lowers_score(): void
    {
        $s = new TailorScorer;
        $good = $s->score(['garment_category' => 'senator', 'city' => 'Abuja'], $this->tailor());
        $bad = $s->score(['garment_category' => 'bridal', 'city' => 'Kano'], $this->tailor());
        $this->assertLessThan($good['score'] - 30, $bad['score']);
    }

    public function test_new_vendor_gets_neutral_prior_and_honest_label(): void
    {
        $r = (new TailorScorer)->score(
            ['garment_category' => 'senator', 'city' => 'Abuja'],
            $this->tailor(['completed_orders' => 0, 'on_time_orders' => 0, 'median_response_minutes' => null]),
        );
        $this->assertTrue($r['new_vendor']);
        $this->assertSame(0.6, $r['factors']['reliability']);
        $this->assertContains('New on Aṣọlà — limited order history so far.', $r['reasons']);
        // Discoverable: a relevant local newcomer still clears a reasonable bar.
        $this->assertGreaterThan(60, $r['score']);
    }

    public function test_complaints_reduce_reliability(): void
    {
        $s = new TailorScorer;
        $clean = $s->score(['garment_category' => 'senator', 'city' => 'Abuja'], $this->tailor());
        $complained = $s->score(['garment_category' => 'senator', 'city' => 'Abuja'], $this->tailor(['upheld_complaints' => 8]));
        $this->assertLessThan($clean['factors']['reliability'], $complained['factors']['reliability']);
    }

    public function test_full_capacity_is_disclosed(): void
    {
        $r = (new TailorScorer)->score(['garment_category' => 'senator', 'city' => 'Abuja'], $this->tailor(['open_orders' => 8]));
        $this->assertSame(0.0, $r['factors']['capacity']);
        $this->assertContains('Currently at full capacity — timelines may be longer.', $r['reasons']);
    }

    public function test_quote_comparison_badges_and_missing_details(): void
    {
        $a = (new QuoteComparison)->analyse([
            ['id' => 1, 'amount_minor' => 3_000_000, 'delivery_days' => 7, 'includes_fitting' => true, 'alterations_included' => 1, 'delivery_fee_minor' => 0],
            ['id' => 2, 'amount_minor' => 2_800_000, 'delivery_days' => 10, 'includes_fitting' => null, 'alterations_included' => 2, 'delivery_fee_minor' => null],
        ]);
        $this->assertContains('Fastest', $a[1]['badges']);
        $this->assertContains('Lowest price', $a[2]['badges']);
        $this->assertContains('Fitting not specified', $a[2]['flags']);
        $this->assertContains('Delivery cost not stated', $a[2]['flags']);
        $this->assertSame([], $a[1]['flags']);
    }

    public function test_very_low_quote_prompts_a_question_not_an_accusation(): void
    {
        $base = ['delivery_days' => 7, 'includes_fitting' => true, 'alterations_included' => 1, 'delivery_fee_minor' => 0];
        $a = (new QuoteComparison)->analyse([
            ['id' => 'a', 'amount_minor' => 3_000_000] + $base,
            ['id' => 'b', 'amount_minor' => 3_200_000] + $base,
            ['id' => 'c', 'amount_minor' => 1_000_000] + $base,
        ]);
        $this->assertContains('Much lower than other offers', $a['c']['flags']);
        foreach ($a['c']['flags'] as $flag) {
            $this->assertFalse(str_contains(strtolower($flag), 'fraud'));
        }
        $this->assertNotContains('Much lower than other offers', $a['a']['flags']);
    }

    public function test_single_offer_gets_no_comparative_badges(): void
    {
        $a = (new QuoteComparison)->analyse([
            ['id' => 1, 'amount_minor' => 100, 'delivery_days' => 3, 'includes_fitting' => true, 'alterations_included' => 1, 'delivery_fee_minor' => 0],
        ]);
        $this->assertSame([], $a[1]['badges']);
    }
}
