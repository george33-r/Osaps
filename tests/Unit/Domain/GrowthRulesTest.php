<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\AsoEbi\AsoEbiRules;
use App\Domain\AsoEbi\AsoEbiStatus;
use App\Domain\Diaspora\CurrencyDisplay;
use App\Domain\Diaspora\ShippingZones;
use App\Domain\Live\LiveLink;
use App\Domain\Measurements\MeasurementSet;
use App\Domain\Shop\CartLine;
use App\Domain\Shop\CartPricing;
use App\Domain\Shop\PayoutRules;
use App\Domain\Support\DomainRuleViolation;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class GrowthRulesTest extends TestCase
{
    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (DomainRuleViolation $e) {
            return $e->rule;
        }

        return 'none';
    }

    private function create(array $o = []): string
    {
        $now = new DateTimeImmutable('2026-10-12 12:00');
        $r = new AsoEbiRules();

        return $this->code(fn () => $r->assertCanCreate(
            $o['yards'] ?? 5, $o['min'] ?? 2, $o['guests'] ?? 40,
            $o['deadline'] ?? $now->modify('+20 days'), $o['event'] ?? $now->modify('+30 days'), $now,
            $o['listed'] ?? true, $o['fulfil'] ?? 'deliver_each',
        ));
    }

    public function test_aso_ebi_group_creation_guards(): void
    {
        $now = new DateTimeImmutable('2026-10-12 12:00');
        $this->assertSame('none', $this->create());
        $this->assertSame('fabric_unavailable', $this->create(['listed' => false]));
        $this->assertSame('yards_out_of_range', $this->create(['yards' => 1, 'min' => 2]));
        $this->assertSame('yards_out_of_range', $this->create(['yards' => 51]));
        $this->assertSame('guests_out_of_range', $this->create(['guests' => 1]));
        $this->assertSame('deadline_past', $this->create(['deadline' => $now->modify('-1 hour')]));
        $this->assertSame('deadline_too_far', $this->create(['deadline' => $now->modify('+121 days'), 'event' => $now->modify('+130 days')]));
        $this->assertSame('event_before_deadline', $this->create(['deadline' => $now->modify('+20 days'), 'event' => $now->modify('+10 days')]));
        $this->assertSame('fulfilment_invalid', $this->create(['fulfil' => 'drone']));
    }

    public function test_bulk_deal_covers_shares_only_up_to_agreed_quantity(): void
    {
        $r = new AsoEbiRules();
        $this->assertTrue($r->dealApplies(380_000, 300, 290, 10));
        $this->assertFalse($r->dealApplies(380_000, 300, 295, 10));
        $this->assertFalse($r->dealApplies(null, null, 0, 5));
    }

    public function test_joining_and_cancelling(): void
    {
        $r = new AsoEbiRules();
        $now = new DateTimeImmutable('2026-10-12 12:00');
        $this->assertSame('none', $this->code(fn () => $r->assertCanJoin('open', $now->modify('+1 day'), $now, 5, 2)));
        $this->assertSame('group_closed', $this->code(fn () => $r->assertCanJoin('closed', $now->modify('+1 day'), $now, 5, 2)));
        $this->assertSame('group_closed', $this->code(fn () => $r->assertCanJoin('open', $now, $now, 5, 2)));
        $this->assertSame('yards_out_of_range', $this->code(fn () => $r->assertCanJoin('open', $now->modify('+1 day'), $now, 1, 2)));
        $this->assertSame('group_has_payments', $this->code(fn () => $r->assertCanCancel(3)));
        $this->assertSame('none', $this->code(fn () => $r->assertCanCancel(0)));
        $this->assertSame(['paid' => 37, 'target' => 60, 'percent' => 61], $r->progress(37, 60));
        $this->assertSame(100, $r->progress(70, 60)['percent']);
        $this->assertTrue(AsoEbiStatus::can('open', 'closed', 'system'));
        $this->assertFalse(AsoEbiStatus::can('closed', 'open', 'system'));
        $this->assertSame('pickup', AsoEbiRules::guestFulfilment('pickup_organizer'));
        $this->assertTrue(AsoEbiRules::isCode('k7mx2pqrst'));
        $this->assertFalse(AsoEbiRules::isCode('K7MX2PQRST'));
        $this->assertFalse(AsoEbiRules::isCode('k7mx2pqrsl')); // no look-alike letters
    }

    public function test_delivery_options_and_international_fee_override(): void
    {
        $p = new CartPricing(serviceFeeBps: 0, serviceFeeCapMinor: 0, deliveryFeePerVendorMinor: 300_000);
        $line = new CartLine(1, 10, 'V', 'F', 'yard', 2, 100_000, 50, 1, true);
        $this->assertSame(300_000, $p->quote([$line], 'arrival')['delivery']);
        $this->assertSame(2_500_000, $p->quote([$line], 'international', 2_500_000)['delivery']);
        $this->assertSame(0, $p->quote([$line], 'pickup', 2_500_000)['delivery']);
        $this->assertSame(['fulfilment_invalid'], array_column($p->quote([$line], 'teleport')['blocking'], 'code'));
    }

    public function test_group_deal_price_only_ever_lowers_the_price(): void
    {
        $p = new CartPricing(serviceFeeBps: 0, serviceFeeCapMinor: 0, deliveryFeePerVendorMinor: 0);
        $deal = new CartLine(1, 10, 'V', 'F', 'yard', 5, 450_000, 50, 1, true, fixedUnitMinor: 380_000);
        $this->assertSame(380_000, $p->quote([$deal], 'pickup')['vendors'][0]['lines'][0]['unit_minor']);
        $odd = new CartLine(1, 10, 'V', 'F', 'yard', 5, 450_000, 50, 1, true, fixedUnitMinor: 500_000);
        $this->assertSame(450_000, $p->quote([$odd], 'pickup')['vendors'][0]['lines'][0]['unit_minor']);
    }

    public function test_shipping_zones(): void
    {
        $z = new ShippingZones(['uk' => 2_500_000, 'us' => 3_000_000, 'eu' => 2_800_000]);
        $this->assertSame(2_500_000, $z->feeFor('gb'));
        $this->assertSame(3_000_000, $z->feeFor('US'));
        $this->assertSame('country_is_nigeria', $this->code(fn () => $z->feeFor('NG')));
        $this->assertSame('country_not_served', $this->code(fn () => $z->feeFor('CA'))); // no fee configured
        $this->assertSame('country_not_served', $this->code(fn () => $z->feeFor('ZZ')));
    }

    public function test_currency_display_is_approximate_and_ignores_bad_rates(): void
    {
        $c = new CurrencyDisplay(['GBP' => 2050, 'USD' => 1550, 'EUR' => 0, 'XYZ' => 10]);
        $this->assertSame(['GBP' => 2050.0, 'USD' => 1550.0], $c->rates());
        $this->assertSame(220, $c->convert(450_000, 'GBP')); // ₦4,500 ≈ £2.20
        $this->assertNull($c->convert(450_000, 'EUR'));
    }

    public function test_payout_held_until_completed_and_auto_completes_after_grace(): void
    {
        $p = new PayoutRules(autoCompleteDays: 3);
        $d = new DateTimeImmutable('2026-10-12 10:00');
        $this->assertFalse($p->shouldAutoComplete('delivered', $d, $d->modify('+71 hours')));
        $this->assertTrue($p->shouldAutoComplete('delivered', $d, $d->modify('+72 hours')));
        $this->assertFalse($p->shouldAutoComplete('out_for_delivery', $d, $d->modify('+10 days')));
        $this->assertFalse($p->shouldAutoComplete('delivered', $d, $d->modify('+10 days'), disputeOpen: true));
        $this->assertSame(7, (new PayoutRules)->autoCompleteDays);
        $this->assertSame('held', PayoutRules::forStatus('delivered'));
        $this->assertSame('releasable', PayoutRules::forStatus('completed'));
        $this->assertSame('withheld', PayoutRules::forStatus('cancelled'));
    }

    public function test_live_links_must_be_https_on_the_named_platform(): void
    {
        $this->assertSame('none', $this->code(fn () => LiveLink::assertValid('tiktok', 'https://www.tiktok.com/@balogunbolts/live')));
        $this->assertSame('url_invalid', $this->code(fn () => LiveLink::assertValid('tiktok', 'http://www.tiktok.com/@x')));
        $this->assertSame('url_invalid', $this->code(fn () => LiveLink::assertValid('tiktok', 'https://tiktok.com.evil.example/@x')));
        $this->assertSame('url_invalid', $this->code(fn () => LiveLink::assertValid('youtube', 'https://user@youtube.com/watch?v=abc')));
        $this->assertSame('url_invalid', $this->code(fn () => LiveLink::assertValid('instagram', 'javascript:alert(1)')));
        $this->assertSame('platform_invalid', $this->code(fn () => LiveLink::assertValid('myspace', 'https://myspace.com')));
        $this->assertSame('dQw4w9WgXcQ', LiveLink::youtubeId('https://www.youtube.com/watch?v=dQw4w9WgXcQ'));
        $this->assertSame('dQw4w9WgXcQ', LiveLink::youtubeId('https://youtu.be/dQw4w9WgXcQ'));
        $this->assertSame('dQw4w9WgXcQ', LiveLink::youtubeId('https://www.youtube.com/live/dQw4w9WgXcQ?si=x'));
        $this->assertNull(LiveLink::youtubeId('https://www.youtube.com/@channel'));
    }

    public function test_measurements_are_stored_in_cm_and_sanity_checked(): void
    {
        $this->assertSame(['chest' => 101.6, 'waist' => 81.3], MeasurementSet::normalise(['chest' => 40, 'waist' => '32', 'hip' => ''], 'in'));
        $this->assertSame('measurement_out_of_range', $this->code(fn () => MeasurementSet::normalise(['chest' => 40], 'cm')));
        $this->assertSame('measurement_unknown', $this->code(fn () => MeasurementSet::normalise(['shoe' => 42], 'cm')));
        $this->assertSame('measurement_invalid', $this->code(fn () => MeasurementSet::normalise(['chest' => 'big'], 'cm')));
        $this->assertSame('unit_invalid', $this->code(fn () => MeasurementSet::normalise([], 'mm')));
    }
}
