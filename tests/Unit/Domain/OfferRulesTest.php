<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Money\Money;
use App\Domain\Negotiation\OfferRules;
use App\Domain\Support\DomainRuleViolation;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class OfferRulesTest extends TestCase
{
    private OfferRules $rules;

    protected function setUp(): void
    {
        $this->rules = new OfferRules(offerExpiryHours: 48, maxCounterRoundsPerSide: 3, maxLiveOffersPerTailor: 2, maxOffersPerRequest: 3);
    }

    public function test_offers_expire_after_configured_hours(): void
    {
        $now = new DateTimeImmutable('2026-10-11 10:00:00');
        $exp = $this->rules->expiresAt($now);
        $this->assertSame('2026-10-13 10:00:00', $exp->format('Y-m-d H:i:s'));
        $this->assertFalse($this->rules->isExpired($exp, $now->modify('+47 hours')));
        $this->assertTrue($this->rules->isExpired($exp, $now->modify('+48 hours')));
    }

    public function test_budget_mode_accepting_exact_budget_needs_no_explanation(): void
    {
        $this->assertFalse($this->rules->explanationRequired('budget', Money::major(30_000), Money::major(30_000)));
    }

    public function test_budget_mode_counter_needs_explanation_up_or_down(): void
    {
        $this->assertTrue($this->rules->explanationRequired('budget', Money::major(35_000), Money::major(30_000)));
        $this->assertTrue($this->rules->explanationRequired('budget', Money::major(25_000), Money::major(30_000)));
    }

    public function test_open_mode_only_needs_explanation_above_stated_max(): void
    {
        $this->assertFalse($this->rules->explanationRequired('open', Money::major(25_000), Money::major(30_000)));
        $this->assertTrue($this->rules->explanationRequired('open', Money::major(31_000), Money::major(30_000)));
        $this->assertFalse($this->rules->explanationRequired('open', Money::major(99_000), null));
    }

    public function test_short_explanation_is_rejected(): void
    {
        $this->expectException(DomainRuleViolation::class);
        $this->rules->assertExplanation('budget', Money::major(35_000), Money::major(30_000), 'too low');
    }

    public function test_adequate_explanation_is_accepted(): void
    {
        $this->rules->assertExplanation('budget', Money::major(35_000), Money::major(30_000), 'Hand-beaded neckline adds two days of work.');
        $this->assertTrue(true);
    }

    public function test_counter_rounds_are_limited(): void
    {
        $this->rules->assertCanCounter(2);
        $this->expectException(DomainRuleViolation::class);
        $this->rules->assertCanCounter(3);
    }

    public function test_cannot_offer_on_own_request(): void
    {
        $this->assertRule('own_request', fn () => $this->rules->assertCanOpenOffer('open', false, 0, 0, true));
    }

    public function test_cannot_offer_on_closed_request(): void
    {
        $this->assertRule('request_closed', fn () => $this->rules->assertCanOpenOffer('awarded', false, 0, 0, false));
    }

    public function test_one_offer_thread_per_tailor_per_request(): void
    {
        $this->assertRule('duplicate_offer', fn () => $this->rules->assertCanOpenOffer('open', true, 1, 1, false));
    }

    public function test_request_and_tailor_offer_limits(): void
    {
        $this->assertRule('request_offer_limit', fn () => $this->rules->assertCanOpenOffer('open', false, 3, 0, false));
        $this->assertRule('tailor_live_offer_limit', fn () => $this->rules->assertCanOpenOffer('open', false, 0, 2, false));
    }

    private function assertRule(string $rule, callable $fn): void
    {
        try {
            $fn();
        } catch (DomainRuleViolation $e) {
            $this->assertSame($rule, $e->rule);

            return;
        }
        $this->assertTrue(false, "Expected rule violation '{$rule}'");
    }
}
