<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Money\Money;
use App\Domain\Payments\ChargeCheck;
use App\Domain\Payments\PaystackSignature;
use App\Domain\Shop\CartLine;
use App\Domain\Shop\CartPricing;
use App\Domain\Shop\FabricOrderStatus;
use App\Domain\Shop\PriceOfferRules;
use App\Domain\Shop\PriceOfferStatus;
use App\Domain\Support\DomainRuleViolation;
use PHPUnit\Framework\TestCase;

final class ShopRulesTest extends TestCase
{
    private PriceOfferRules $rules;

    protected function setUp(): void
    {
        $this->rules = new PriceOfferRules(floorBps: 7_000, maxRoundsPerSide: 3, maxLivePerBuyer: 2);
    }

    private function code(callable $fn): string
    {
        try {
            $fn();
        } catch (DomainRuleViolation $e) {
            return $e->rule;
        }

        return 'none';
    }

    private function open(int $offerNaira, int $qty = 5, int $listNaira = 4_500, array $o = []): string
    {
        return $this->code(fn () => $this->rules->assertCanOpen(
            Money::major($listNaira), Money::major($offerNaira), $qty, $o['min'] ?? 1, $o['available'] ?? 100,
            $o['own'] ?? false, $o['listed'] ?? true, $o['live'] ?? 0, $o['dup'] ?? false,
        ));
    }

    public function test_floor_is_share_of_list_rounded_up_to_fifty_naira(): void
    {
        // 70% of ₦4,500 is ₦3,150 exactly; 70% of ₦3,830 is ₦2,681, rounded up to ₦2,700.
        $this->assertSame(315_000, $this->rules->floor(Money::major(4_500))->minor);
        $this->assertSame(270_000, $this->rules->floor(Money::major(3_830))->minor);
    }

    public function test_suggestions_are_below_list_above_floor_and_distinct(): void
    {
        $s = array_map(fn (Money $m) => $m->minor, $this->rules->suggestions(Money::major(4_500)));
        $this->assertSame([425_000, 405_000, 380_000], $s); // rounded down to ₦50
        // A tiny price collapses suggestions onto the floor without duplicates.
        $tiny = array_map(fn (Money $m) => $m->minor, $this->rules->suggestions(Money::major(100)));
        $this->assertSame(count($tiny), count(array_unique($tiny)));
        foreach ($tiny as $m) {
            $this->assertTrue($m < 10_000);
        }
    }

    public function test_buyer_offer_must_be_below_list_and_at_or_above_floor(): void
    {
        $this->assertSame('none', $this->open(3_150));
        $this->assertSame('below_floor', $this->open(3_100));
        $this->assertSame('at_or_above_list', $this->open(4_500));
        $this->assertSame('at_or_above_list', $this->open(5_000));
    }

    public function test_buyer_offer_guards(): void
    {
        $this->assertSame('own_listing', $this->open(4_000, 5, 4_500, ['own' => true]));
        $this->assertSame('fabric_unavailable', $this->open(4_000, 5, 4_500, ['listed' => false]));
        $this->assertSame('below_min_quantity', $this->open(4_000, 2, 4_500, ['min' => 3]));
        $this->assertSame('insufficient_stock', $this->open(4_000, 12, 4_500, ['available' => 10]));
        $this->assertSame('duplicate_offer', $this->open(4_000, 5, 4_500, ['dup' => true]));
        $this->assertSame('buyer_live_offer_limit', $this->open(4_000, 5, 4_500, ['live' => 2]));
    }

    public function test_vendor_counter_sits_between_buyer_offer_and_list(): void
    {
        $list = Money::major(4_500);
        $buyer = Money::major(3_600);
        $this->assertSame('none', $this->code(fn () => $this->rules->assertVendorCounter($list, $buyer, Money::major(4_100), 0)));
        $this->assertSame('none', $this->code(fn () => $this->rules->assertVendorCounter($list, $buyer, Money::major(4_500), 0)));
        $this->assertSame('counter_too_low', $this->code(fn () => $this->rules->assertVendorCounter($list, $buyer, Money::major(3_600), 0)));
        $this->assertSame('counter_above_list', $this->code(fn () => $this->rules->assertVendorCounter($list, $buyer, Money::major(4_600), 0)));
        $this->assertSame('counter_limit', $this->code(fn () => $this->rules->assertVendorCounter($list, $buyer, Money::major(4_100), 3)));
    }

    public function test_buyer_can_only_raise_and_must_stay_below_vendor(): void
    {
        $list = Money::major(4_500);
        $prev = Money::major(3_600);
        $vendor = Money::major(4_200);
        $this->assertSame('none', $this->code(fn () => $this->rules->assertBuyerCounter($list, $prev, $vendor, Money::major(3_900), 1)));
        $this->assertSame('counter_not_raised', $this->code(fn () => $this->rules->assertBuyerCounter($list, $prev, $vendor, Money::major(3_500), 1)));
        $this->assertSame('counter_meets_vendor', $this->code(fn () => $this->rules->assertBuyerCounter($list, $prev, $vendor, Money::major(4_200), 1)));
        $this->assertSame('counter_limit', $this->code(fn () => $this->rules->assertBuyerCounter($list, $prev, $vendor, Money::major(3_900), 3)));
    }

    public function test_only_the_side_whose_turn_it_is_can_act(): void
    {
        $this->assertTrue(PriceOfferStatus::can('awaiting_vendor', 'accepted', 'vendor'));
        $this->assertFalse(PriceOfferStatus::can('awaiting_vendor', 'accepted', 'buyer'));
        $this->assertTrue(PriceOfferStatus::can('awaiting_buyer', 'accepted', 'buyer'));
        $this->assertFalse(PriceOfferStatus::can('awaiting_buyer', 'accepted', 'vendor'));
        $this->assertFalse(PriceOfferStatus::can('awaiting_buyer', 'withdrawn', 'vendor'));
        $this->assertTrue(PriceOfferStatus::can('accepted', 'used', 'system'));
        $this->assertFalse(PriceOfferStatus::can('accepted', 'used', 'buyer'));
        $this->assertTrue(PriceOfferStatus::isTerminal('used'));
    }

    private function line(array $o = []): CartLine
    {
        return new CartLine(
            fabricId: $o['id'] ?? 1, vendorId: $o['vendor'] ?? 10, vendorName: 'V', fabricName: $o['name'] ?? 'Indigo',
            unit: 'yard', quantity: $o['qty'] ?? 5, listUnitMinor: $o['list'] ?? 450_000, available: $o['available'] ?? 100,
            minQuantity: $o['min'] ?? 1, listed: $o['listed'] ?? true, offerId: $o['offer'] ?? null,
            offerUnitMinor: $o['offerUnit'] ?? null, offerQuantity: $o['offerQty'] ?? null, offerUsable: $o['usable'] ?? false,
        );
    }

    public function test_cart_totals_split_by_vendor_with_delivery_and_capped_fee(): void
    {
        $p = new CartPricing(serviceFeeBps: 250, serviceFeeCapMinor: 500_000, deliveryFeePerVendorMinor: 300_000);
        $q = $p->quote([$this->line(), $this->line(['id' => 2, 'vendor' => 11, 'list' => 600_000, 'qty' => 2])], 'delivery');
        $this->assertTrue($q['ok']);
        $this->assertCount(2, $q['vendors']);
        $this->assertSame(2_250_000 + 1_200_000, $q['subtotal']);
        $this->assertSame(600_000, $q['delivery']);
        $this->assertSame(86_250, $q['service_fee']); // 2.5% of ₦34,500
        $this->assertSame(3_450_000 + 600_000 + 86_250, $q['total']);

        $pickup = $p->quote([$this->line()], 'pickup');
        $this->assertSame(0, $pickup['delivery']);

        $big = $p->quote([$this->line(['qty' => 100, 'list' => 9_000_000, 'available' => 1000])], 'pickup');
        $this->assertSame(500_000, $big['service_fee']);
    }

    public function test_agreed_price_applies_only_when_usable_and_within_quantity(): void
    {
        $p = new CartPricing();
        $ok = $p->quote([$this->line(['offer' => 7, 'offerUnit' => 380_000, 'offerQty' => 5, 'usable' => true])], 'pickup');
        $this->assertSame(380_000, $ok['vendors'][0]['lines'][0]['unit_minor']);
        $this->assertSame(7, $ok['vendors'][0]['lines'][0]['price_offer_id']);
        $this->assertSame(350_000, $ok['vendors'][0]['lines'][0]['saving_minor']);

        $lapsed = $p->quote([$this->line(['offer' => 7, 'offerUnit' => 380_000, 'offerQty' => 5, 'usable' => false])], 'pickup');
        $this->assertSame(450_000, $lapsed['vendors'][0]['lines'][0]['unit_minor']);
        $this->assertSame('offer_not_usable', $lapsed['notices'][0]['code']);
        $this->assertTrue($lapsed['ok']);

        $tooMany = $p->quote([$this->line(['qty' => 8, 'offer' => 7, 'offerUnit' => 380_000, 'offerQty' => 5, 'usable' => true])], 'pickup');
        $this->assertSame(450_000, $tooMany['vendors'][0]['lines'][0]['unit_minor']);
        $this->assertSame('offer_quantity', $tooMany['notices'][0]['code']);

        // Even a corrupted offer above the list price can never raise the price.
        $weird = $p->quote([$this->line(['offer' => 7, 'offerUnit' => 999_000, 'offerQty' => 5, 'usable' => true])], 'pickup');
        $this->assertSame(450_000, $weird['vendors'][0]['lines'][0]['unit_minor']);
    }

    public function test_blocking_problems_stop_checkout(): void
    {
        $p = new CartPricing();
        $codes = fn (array $q) => array_column($q['blocking'], 'code');
        $this->assertSame(['empty_cart'], $codes($p->quote([], 'delivery')));
        $this->assertSame(['fabric_unavailable'], $codes($p->quote([$this->line(['listed' => false])], 'delivery')));
        $this->assertSame(['insufficient_stock'], $codes($p->quote([$this->line(['qty' => 9, 'available' => 4])], 'delivery')));
        $this->assertSame(['below_min_quantity'], $codes($p->quote([$this->line(['qty' => 1, 'min' => 3])], 'delivery')));
        $this->assertSame(['duplicate_line'], $codes($p->quote([$this->line(), $this->line()], 'delivery')));
        $this->assertSame(['fulfilment_invalid'], $codes($p->quote([$this->line()], 'drone')));
        $this->assertFalse($p->quote([$this->line(['listed' => false])], 'delivery')['ok']);
    }

    public function test_service_fee_allocation_sums_exactly(): void
    {
        $parts = CartPricing::allocate(86_251, [2_250_000, 1_200_000, 333]);
        $this->assertSame(86_251, array_sum($parts));
        $this->assertSame([0, 0], CartPricing::allocate(0, [0, 0]));
    }

    public function test_fabric_order_leaves_pending_only_by_verified_payment(): void
    {
        $this->assertTrue(FabricOrderStatus::can('pending_payment', 'paid', 'system'));
        $this->assertFalse(FabricOrderStatus::can('pending_payment', 'paid', 'customer'));
        $this->assertFalse(FabricOrderStatus::can('pending_payment', 'paid', 'vendor'));
        $this->assertFalse(FabricOrderStatus::can('paid', 'cancelled', 'customer'));
        $this->assertTrue(FabricOrderStatus::can('paid', 'cancelled', 'admin'));
        $this->assertTrue(FabricOrderStatus::can('packed', 'ready_for_pickup', 'vendor'));
        $this->assertFalse(FabricOrderStatus::can('packed', 'delivered', 'vendor'));
        $this->assertSame('ready_for_pickup', FabricOrderStatus::dispatchStep('pickup'));
        $this->assertSame('out_for_delivery', FabricOrderStatus::dispatchStep('delivery'));
    }

    public function test_paystack_signature(): void
    {
        $body = '{"event":"charge.success","data":{"reference":"OSP-ABCDEFGHJK234567"}}';
        $sig = PaystackSignature::sign($body, 'sk_test_x');
        $this->assertTrue(PaystackSignature::verify($body, $sig, 'sk_test_x'));
        $this->assertTrue(PaystackSignature::verify($body, strtoupper($sig), 'sk_test_x'));
        $this->assertFalse(PaystackSignature::verify($body.' ', $sig, 'sk_test_x'));
        $this->assertFalse(PaystackSignature::verify($body, $sig, 'sk_test_other'));
        $this->assertFalse(PaystackSignature::verify($body, null, 'sk_test_x'));
        $this->assertFalse(PaystackSignature::verify($body, $sig, ''));
    }

    public function test_charge_must_match_reference_status_currency_and_amount(): void
    {
        $ref = 'OSP-ABCDEFGHJK234567';
        $good = ['reference' => $ref, 'status' => 'success', 'currency' => 'NGN', 'amount' => 4_136_250];
        $c = fn (array $d, int $exp = 4_136_250) => $this->code(fn () => ChargeCheck::assertPays($d, $ref, $exp, 'NGN'));
        $this->assertSame('none', $c($good));
        $this->assertSame('reference_mismatch', $c(['reference' => 'OSP-OTHER'] + $good));
        $this->assertSame('payment_not_successful', $c(['status' => 'abandoned'] + $good));
        $this->assertSame('currency_mismatch', $c(['currency' => 'USD'] + $good));
        $this->assertSame('amount_mismatch', $c(['amount' => 4_136_249] + $good));
        $this->assertSame('amount_unreadable', $c(['amount' => '41.36'] + $good));
        $this->assertTrue(ChargeCheck::isOurReference($ref));
        $this->assertFalse(ChargeCheck::isOurReference('OSP-abc'));
        $this->assertFalse(ChargeCheck::isOurReference("OSP-ABCDEFGHJK234567\n"));
    }
}
