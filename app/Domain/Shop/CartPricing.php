<?php

declare(strict_types=1);

namespace App\Domain\Shop;

use App\Domain\Money\Money;

/**
 * Prices a fabric cart on the server. Every line is checked against live listing data; problems
 * that make a line unbuyable block checkout, while softer ones (an agreed price that lapsed) fall
 * back to the list price and are reported so the buyer sees the change before paying.
 *
 * The cart is split by vendor: each vendor becomes its own order, with its own delivery fee.
 */
final class CartPricing
{
    public const MAX_LINES = 30;

    public function __construct(
        public readonly int $serviceFeeBps = 250,
        public readonly int $serviceFeeCapMinor = 500_000,
        public readonly int $deliveryFeePerVendorMinor = 300_000,
        public readonly string $currency = 'NGN',
    ) {}

    /**
     * @param  list<CartLine>  $lines
     * @return array{
     *   vendors: list<array{vendor_id:int, vendor_name:string, lines: list<array<string,mixed>>, subtotal:int, delivery:int, total:int}>,
     *   subtotal:int, delivery:int, service_fee:int, total:int,
     *   blocking: list<array{fabric_id:int, code:string, message:string}>,
     *   notices: list<array{fabric_id:int, code:string, message:string}>,
     *   ok: bool
     * }
     */
    public const FULFILMENTS = ['delivery', 'pickup', 'arrival', 'international'];

    /**
     * $deliveryFeePerVendor overrides the default per-vendor delivery charge (international
     * shipping is priced per destination region by the caller). Pickup is always free.
     */
    public function quote(array $lines, string $fulfilment, ?int $deliveryFeePerVendor = null): array
    {
        $blocking = [];
        $notices = [];
        $vendors = [];
        $seen = [];

        if ($lines === []) {
            $blocking[] = ['fabric_id' => 0, 'code' => 'empty_cart', 'message' => 'Your cart is empty.'];
        }
        if (count($lines) > self::MAX_LINES) {
            $blocking[] = ['fabric_id' => 0, 'code' => 'too_many_lines', 'message' => 'A cart can hold up to '.self::MAX_LINES.' fabrics.'];
        }
        if (! in_array($fulfilment, self::FULFILMENTS, true)) {
            $blocking[] = ['fabric_id' => 0, 'code' => 'fulfilment_invalid', 'message' => 'Choose how you want to receive your fabric.'];
        }
        $fee = $fulfilment === 'pickup' ? 0 : max(0, $deliveryFeePerVendor ?? $this->deliveryFeePerVendorMinor);

        foreach ($lines as $line) {
            if (isset($seen[$line->fabricId])) {
                $blocking[] = ['fabric_id' => $line->fabricId, 'code' => 'duplicate_line', 'message' => "{$line->fabricName} is in your cart twice."];

                continue;
            }
            $seen[$line->fabricId] = true;

            if (! $line->listed) {
                $blocking[] = ['fabric_id' => $line->fabricId, 'code' => 'fabric_unavailable', 'message' => "{$line->fabricName} is no longer listed."];

                continue;
            }
            $min = max(1, $line->minQuantity);
            if ($line->quantity < $min) {
                $blocking[] = ['fabric_id' => $line->fabricId, 'code' => 'below_min_quantity', 'message' => "{$line->fabricName}: the minimum is {$min} {$line->unit}."];
            }
            if ($line->quantity > $line->available) {
                $blocking[] = ['fabric_id' => $line->fabricId, 'code' => 'insufficient_stock', 'message' => $line->available > 0
                    ? "{$line->fabricName}: only {$line->available} {$line->unit} left."
                    : "{$line->fabricName} is out of stock."];
            }

            $unit = $line->listUnitMinor;
            $offerApplied = null;
            if ($line->fixedUnitMinor !== null) {
                $unit = min($line->fixedUnitMinor, $line->listUnitMinor);
            } elseif ($line->offerId !== null) {
                if (! $line->offerUsable || $line->offerUnitMinor === null) {
                    $notices[] = ['fabric_id' => $line->fabricId, 'code' => 'offer_not_usable', 'message' => "{$line->fabricName}: your agreed price is no longer valid, so the listed price applies."];
                } elseif ($line->offerQuantity !== null && $line->quantity > $line->offerQuantity) {
                    $notices[] = ['fabric_id' => $line->fabricId, 'code' => 'offer_quantity', 'message' => "{$line->fabricName}: your agreed price covers up to {$line->offerQuantity} {$line->unit}. Lower the quantity to use it."];
                } else {
                    // An agreed price can only ever lower the cost, never raise it above the listing.
                    $unit = min($line->offerUnitMinor, $line->listUnitMinor);
                    $offerApplied = $line->offerId;
                }
            }

            $lineTotal = $unit * max(0, $line->quantity);
            $vendors[$line->vendorId] ??= ['vendor_id' => $line->vendorId, 'vendor_name' => $line->vendorName, 'lines' => [], 'subtotal' => 0, 'delivery' => 0, 'total' => 0];
            $vendors[$line->vendorId]['lines'][] = [
                'fabric_id' => $line->fabricId,
                'name' => $line->fabricName,
                'unit' => $line->unit,
                'quantity' => $line->quantity,
                'list_unit_minor' => $line->listUnitMinor,
                'unit_minor' => $unit,
                'line_total_minor' => $lineTotal,
                'price_offer_id' => $offerApplied,
                'saving_minor' => ($line->listUnitMinor - $unit) * max(0, $line->quantity),
            ];
            $vendors[$line->vendorId]['subtotal'] += $lineTotal;
        }

        $subtotal = 0;
        $delivery = 0;
        foreach ($vendors as &$v) {
            $v['delivery'] = in_array($fulfilment, self::FULFILMENTS, true) ? $fee : 0;
            $v['total'] = $v['subtotal'] + $v['delivery'];
            $subtotal += $v['subtotal'];
            $delivery += $v['delivery'];
        }
        unset($v);

        $fee = Money::minor($subtotal, $this->currency)->percentBps($this->serviceFeeBps)->minor;
        if ($this->serviceFeeCapMinor > 0) {
            $fee = min($fee, $this->serviceFeeCapMinor);
        }

        return [
            'vendors' => array_values($vendors),
            'subtotal' => $subtotal,
            'delivery' => $delivery,
            'service_fee' => $fee,
            'total' => $subtotal + $delivery + $fee,
            'blocking' => $blocking,
            'notices' => $notices,
            'ok' => $blocking === [],
        ];
    }

    /**
     * Splits the checkout's service fee across vendor orders in proportion to their goods, so the
     * orders add up exactly to what the buyer paid (any remainder goes to the first order).
     *
     * @param  list<int>  $subtotals
     * @return list<int>
     */
    public static function allocate(int $fee, array $subtotals): array
    {
        $sum = array_sum($subtotals);
        if ($sum === 0 || $subtotals === []) {
            return array_map(fn () => 0, $subtotals);
        }
        $parts = array_map(fn (int $s) => intdiv($fee * $s, $sum), $subtotals);
        $parts[0] += $fee - array_sum($parts);

        return $parts;
    }
}
