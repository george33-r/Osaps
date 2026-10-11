<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Ai\BudgetEstimate;
use App\Domain\Ai\FabricCriteria;
use App\Domain\Ai\Grounding;
use App\Domain\Ai\JsonExtractor;
use App\Domain\Ai\KeywordInterpreter;
use App\Domain\Ai\SchemaValidator;
use App\Domain\Ai\StylistConsultation;
use PHPUnit\Framework\TestCase;

final class AiSafetyTest extends TestCase
{
    private const CATS = ['ankara', 'adire', 'aso_oke', 'lace', 'senator', 'suiting', 'george', 'damask', 'other'];

    private const COLORS = ['navy', 'indigo', 'gold', 'terracotta', 'emerald', 'burgundy', 'cream', 'black', 'white', 'orange', 'royal_blue', 'purple', 'green', 'red', 'brown', 'pink', 'silver'];

    // ---- Output parsing and validation ----

    public function test_extracts_json_wrapped_in_prose_and_fences(): void
    {
        $o = JsonExtractor::object("Sure! Here you go:\n```json\n{\"a\": {\"b\": \"x}y\"}, \"c\": [1,2]}\n```\nAnything else?");
        $this->assertSame(['a' => ['b' => 'x}y'], 'c' => [1, 2]], $o);
    }

    public function test_extractor_returns_null_for_garbage_or_arrays(): void
    {
        $this->assertSame(null, JsonExtractor::object('no json here'));
        $this->assertSame(null, JsonExtractor::object('{"unterminated": '));
        $this->assertSame(null, JsonExtractor::object('[1,2,3]'));
    }

    public function test_schema_rejects_wrong_types_unknown_keys_and_bad_enums(): void
    {
        $schema = ['type' => 'object', 'required' => ['garment'], 'additional' => false, 'properties' => [
            'garment' => ['type' => 'string', 'enum' => ['gown', 'senator']],
            'notes' => ['type' => 'string', 'maxLength' => 10, 'nullable' => true],
            'ids' => ['type' => 'array', 'maxItems' => 2, 'items' => ['type' => 'integer']],
        ]];
        $this->assertTrue(SchemaValidator::valid(['garment' => 'gown', 'notes' => null, 'ids' => [1, 2]], $schema));
        $this->assertFalse(SchemaValidator::valid(['garment' => 'spacesuit'], $schema));
        $this->assertFalse(SchemaValidator::valid(['garment' => 'gown', 'admin' => true], $schema));
        $this->assertFalse(SchemaValidator::valid(['garment' => 'gown', 'ids' => [1, 2, 3]], $schema));
        $this->assertFalse(SchemaValidator::valid(['garment' => 'gown', 'ids' => ['1']], $schema));
        $this->assertFalse(SchemaValidator::valid(['garment' => 'gown', 'notes' => str_repeat('x', 11)], $schema));
        $this->assertFalse(SchemaValidator::valid(['notes' => 'hi'], $schema));
    }

    // ---- Grounding: the model can only choose, never invent ----

    public function test_model_cannot_invent_categories_colours_or_prices(): void
    {
        $c = FabricCriteria::sanitize(
            ['categories' => ['ankara', 'velvet', 'DROP TABLE'], 'colors' => ['burgundy', 'neon'], 'max_price_naira' => -5, 'terms' => ['cashmere', '<script>', 'x']],
            self::CATS, self::COLORS,
        );
        $this->assertSame(['ankara'], $c->categories);
        $this->assertSame(['burgundy'], $c->colors);
        $this->assertSame(null, $c->maxPriceMinor);
        $this->assertSame(['cashmere', 'script'], $c->terms);
    }

    public function test_only_offered_ids_survive(): void
    {
        $this->assertSame([3, 7], Grounding::pick([3, 999, 7, 3, 'x', 8], [3, 7, 8], 2));
        $this->assertSame([], Grounding::pick('not a list', [1], 3));
    }

    public function test_scrub_removes_invented_prices_and_promises(): void
    {
        $out = Grounding::scrubClaims('This lace costs ₦45,000 and is in stock, guaranteed.');
        $this->assertFalse(str_contains($out, '45,000'));
        $this->assertFalse(str_contains(strtolower($out), 'in stock'));
        $this->assertFalse(str_contains(strtolower($out), 'guarantee'));
    }

    public function test_relaxations_loosen_in_a_sensible_order(): void
    {
        $c = new FabricCriteria(['lace'], ['gold'], 1_000_000, true, ['beaded']);
        $dropped = array_column($c->relaxations(), 'dropped');
        $this->assertSame(['price limit', 'colour', 'extra words'], $dropped);
        $last = $c->relaxations()[2]['criteria'];
        $this->assertSame(['lace'], $last->categories);
    }

    // ---- Rule-based fallback reading of everyday requests ----

    public function test_reads_a_wedding_guest_request_with_budget(): void
    {
        $r = KeywordInterpreter::read('A wine-coloured Ankara for a wedding guest under 10k');
        $this->assertSame(['ankara'], $r['categories']);
        $this->assertSame(['burgundy'], $r['colors']);
        $this->assertSame(10_000, $r['max_price_naira']);
        $this->assertSame('wedding_guest', $r['occasion']);
    }

    public function test_reads_aso_oke_with_diacritics_and_blue_family(): void
    {
        $r = KeywordInterpreter::read('Aṣọ-òkè in blue for a traditional wedding');
        $this->assertSame(['aso_oke'], $r['categories']);
        $this->assertSame(['navy', 'royal_blue', 'indigo'], $r['colors']);
        $this->assertSame('traditional_wedding', $r['occasion']);
        $this->assertSame(null, $r['max_price_naira']);
    }

    public function test_reads_festival_and_detty_december_occasions(): void
    {
        $this->assertSame('festival', KeywordInterpreter::read('agbada for Ojude Oba this year')['occasion']);
        $this->assertSame('festival', KeywordInterpreter::read('babban riga for the Durbar in Kano')['occasion']);
        $this->assertSame('december', KeywordInterpreter::read('something loud for Detty December in Lagos')['occasion']);
        // A wedding in December is still a wedding first.
        $this->assertSame('wedding_guest', KeywordInterpreter::read('wedding guest outfit in December')['occasion']);
    }

    public function test_small_numbers_are_not_money(): void
    {
        $this->assertSame(null, KeywordInterpreter::read('fabric for a family of 6')['budget_naira']);
        $this->assertSame(20_000, KeywordInterpreter::read('family of 6, under ₦20,000')['max_price_naira']);
        $this->assertSame(1_500_000, KeywordInterpreter::amount('about 1.5m for the bridal look'));
    }

    // ---- Consultation flow ----

    public function test_consultation_asks_one_missing_thing_at_a_time(): void
    {
        $s = new StylistConsultation(['wedding_guest' => 'Wedding guest', 'other' => 'Something else'], ['gown' => 'Gown', 'senator' => 'Senator', 'alteration' => 'Alteration'], self::COLORS);
        $this->assertSame('occasion', $s->next([])['slot']);
        $q = $s->next(['occasion' => 'wedding_guest']);
        $this->assertSame('garment', $q['slot']);
        $this->assertTrue(str_contains($q['text'], 'wedding guest'));
        $this->assertNotContains(['value' => 'alteration', 'label' => 'Alteration'], $q['choices']);
        $this->assertSame(null, $s->next(['occasion' => 'other', 'garment' => 'gown', 'colors' => [], 'budget_naira' => 0, 'needs' => 'tailoring_only', 'timing' => 'month']));
    }

    public function test_consultation_drops_malformed_answers_so_they_are_asked_again(): void
    {
        $s = new StylistConsultation(['wedding_guest' => 'Wedding guest'], ['gown' => 'Gown'], self::COLORS);
        $clean = $s->clean(['occasion' => 'rave', 'garment' => 'gown', 'colors' => ['gold', 'neon'], 'budget_naira' => -10, 'needs' => 'both', 'timing' => 'month']);
        $this->assertSame(['garment' => 'gown', 'colors' => ['gold'], 'timing' => 'month'], $clean);
        $this->assertSame('occasion', $s->next($clean)['slot']);
    }

    // ---- Budget transparency ----

    public function test_budget_uses_real_prices_and_labels_assumptions(): void
    {
        $b = BudgetEstimate::build(750_000, 5, [2_500_000, 3_000_000, 1_500_000], true, true, 250, 500_000, 6_000_000);
        $this->assertSame(['fabric', 'tailoring', 'service_fee'], array_column($b['lines'], 'key'));
        $this->assertSame(3_750_000, $b['lines'][0]['minor']);
        $this->assertSame('listed × assumption', $b['lines'][0]['basis']);
        $this->assertSame(2_500_000, $b['lines'][1]['minor']); // lower median of real prices
        $this->assertSame(62_500, $b['lines'][2]['minor']);
        $this->assertSame(6_312_500, $b['total_minor']);
        $this->assertFalse($b['within_budget']);
    }

    public function test_budget_names_missing_data_instead_of_guessing(): void
    {
        $b = BudgetEstimate::build(null, 5, [], true, true, 250, 0, 5_000_000);
        $this->assertSame(null, $b['total_minor']);
        $this->assertSame(['a fabric with a listed price', 'tailor prices for this garment'], $b['missing']);
        $this->assertSame(null, $b['within_budget']);
    }

    public function test_tailoring_only_budget_skips_fabric(): void
    {
        $b = BudgetEstimate::build(null, null, [2_000_000], false, true, 250, 500_000, null);
        $this->assertSame(['tailoring', 'service_fee'], array_column($b['lines'], 'key'));
        $this->assertSame(2_050_000, $b['total_minor']);
    }
}
