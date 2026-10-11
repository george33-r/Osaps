<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Domain\Money\Money;

/**
 * Transparent, configurable fee rules. Values come from config/marketplace.php —
 * the defaults there are placeholders for the business to confirm, not decisions.
 */
final class FeeSchedule
{
    public function __construct(
        public readonly int $customerServiceFeeBps,
        public readonly int $customerServiceFeeCapMinor,
        public readonly int $tailorCommissionBps,
    ) {}

    public function customerServiceFee(Money $tailoring): Money
    {
        $fee = $tailoring->percentBps($this->customerServiceFeeBps);

        if ($this->customerServiceFeeCapMinor > 0 && $fee->minor > $this->customerServiceFeeCapMinor) {
            return Money::minor($this->customerServiceFeeCapMinor, $tailoring->currency);
        }

        return $fee;
    }

    /**
     * Customer-facing breakdown. Every charge is a named line; nothing is hidden in the total.
     *
     * @return array{lines: list<array{key:string,label:string,amount:Money}>, total: Money}
     */
    public function customerBreakdown(Money $tailoring, ?Money $delivery = null, ?Money $fabric = null): array
    {
        $currency = $tailoring->currency;
        $lines = [
            ['key' => 'tailoring', 'label' => 'Tailoring', 'amount' => $tailoring],
        ];
        if ($fabric !== null && $fabric->minor > 0) {
            $lines[] = ['key' => 'fabric', 'label' => 'Fabric', 'amount' => $fabric];
        }
        if ($delivery !== null && $delivery->minor > 0) {
            $lines[] = ['key' => 'delivery', 'label' => 'Delivery', 'amount' => $delivery];
        }
        $lines[] = ['key' => 'service_fee', 'label' => 'Aṣọlà service fee', 'amount' => $this->customerServiceFee($tailoring)];

        $total = Money::zero($currency);
        foreach ($lines as $line) {
            $total = $total->add($line['amount']);
        }

        return ['lines' => $lines, 'total' => $total];
    }

    /** @return array{gross: Money, commission: Money, net: Money} */
    public function tailorPayout(Money $tailoring): array
    {
        $commission = $tailoring->percentBps($this->tailorCommissionBps);

        return ['gross' => $tailoring, 'commission' => $commission, 'net' => $tailoring->subtract($commission)];
    }
}
