<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Money\Money;
use App\Domain\Pricing\FeeSchedule;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MoneyAndFeesTest extends TestCase
{
    public function test_money_is_stored_in_minor_units_and_formats_naira(): void
    {
        $m = Money::major(45_000);
        $this->assertSame(4_500_000, $m->minor);
        $this->assertSame('₦45,000', $m->format());
        $this->assertSame('₦12.50', Money::minor(1_250)->format());
    }

    public function test_money_rejects_negative_amounts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::minor(-1);
    }

    public function test_money_rejects_currency_mismatch(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::major(1)->add(Money::major(1, 'GHS'));
    }

    public function test_basis_points_round_half_up(): void
    {
        // 2.5% of ₦333.33 = 833.325 kobo -> 833
        $this->assertSame(833, Money::minor(33_333)->percentBps(250)->minor);
        // 2.5% of 1 kobo... 0.025 -> 0
        $this->assertSame(0, Money::minor(1)->percentBps(250)->minor);
        // 50% of 3 kobo = 1.5 -> 2
        $this->assertSame(2, Money::minor(3)->percentBps(5_000)->minor);
    }

    public function test_customer_breakdown_lists_every_charge_and_sums_exactly(): void
    {
        $fees = new FeeSchedule(customerServiceFeeBps: 250, customerServiceFeeCapMinor: 0, tailorCommissionBps: 1_000);
        $b = $fees->customerBreakdown(Money::major(30_000), Money::major(2_500), Money::major(12_000));

        $keys = array_column($b['lines'], 'key');
        $this->assertSame(['tailoring', 'fabric', 'delivery', 'service_fee'], $keys);
        $this->assertSame(75_000, $b['lines'][3]['amount']->minor); // ₦750
        $this->assertSame(Money::major(45_250)->minor, $b['total']->minor);
    }

    public function test_service_fee_cap_applies(): void
    {
        $fees = new FeeSchedule(customerServiceFeeBps: 250, customerServiceFeeCapMinor: 200_000, tailorCommissionBps: 1_000);
        $this->assertSame(200_000, $fees->customerServiceFee(Money::major(1_000_000))->minor);
    }

    public function test_zero_lines_are_omitted(): void
    {
        $fees = new FeeSchedule(250, 0, 1_000);
        $b = $fees->customerBreakdown(Money::major(10_000), Money::zero(), null);
        $this->assertSame(['tailoring', 'service_fee'], array_column($b['lines'], 'key'));
    }

    public function test_tailor_payout_nets_commission(): void
    {
        $fees = new FeeSchedule(250, 0, 1_000);
        $p = $fees->tailorPayout(Money::major(30_000));
        $this->assertSame(Money::major(3_000)->minor, $p['commission']->minor);
        $this->assertSame(Money::major(27_000)->minor, $p['net']->minor);
    }
}
