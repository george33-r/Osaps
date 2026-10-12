<?php

namespace App\Services;

use App\Domain\AsoEbi\AsoEbiRules;
use App\Domain\Diaspora\ShippingZones;
use App\Domain\Payments\ChargeCheck;
use App\Domain\Shop\PayoutRules;
use App\Domain\Shop\CartLine;
use App\Domain\Shop\CartPricing;
use App\Domain\Shop\FabricOrderStatus;
use App\Domain\Shop\PriceOfferStatus;
use App\Domain\Support\DomainRuleViolation;
use App\Models\AsoEbiGroup;
use App\Models\AsoEbiShare;
use App\Models\Checkout;
use App\Models\Fabric;
use App\Models\FabricOrder;
use App\Models\PriceOffer;
use App\Models\User;
use App\Payments\PaymentGateway;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Fabric checkout, stock and payment confirmation.
 *
 *  - Prices always come from the database (and agreed offers), never from the browser.
 *  - The buyer sends the total they saw; if anything changed, checkout stops and shows the new quote.
 *  - Stock is reserved while the buyer pays and released if they do not.
 *  - Only the payment provider (verify call or signed webhook) can mark a checkout paid, once.
 */
class CheckoutService
{
    public function __construct(
        private readonly CartPricing $pricing,
        private readonly PaymentGateway $gateway,
        private readonly ShopOfferService $offers,
        private readonly Notifier $notifier,
        private readonly ShippingZones $zones,
        private readonly AsoEbiRules $asoEbi,
        private readonly PayoutRules $payouts,
    ) {}

    /** Per-vendor delivery charge for this way of receiving fabric (international is priced by country). */
    private function deliveryFee(string $fulfilment, ?string $country): ?int
    {
        if ($fulfilment !== 'international') {
            return null;
        }
        if ($country === null || $country === '') {
            throw new DomainRuleViolation('Choose the country to ship to.', 'country_required');
        }

        return $this->zones->feeFor($country);
    }

    /**
     * @param  list<array{fabric_id:int, quantity:int, price_offer_id?:int|null}>  $items
     * @return array<string,mixed>
     */
    public function quote(User $buyer, array $items, string $fulfilment, ?string $country = null): array
    {
        $this->sweep();
        $fabrics = Fabric::query()->with('vendor')->whereIn('id', array_column($items, 'fabric_id'))->get()->keyBy('id');

        return $this->pricing->quote($this->lines($buyer, $items, $fabrics), $fulfilment, $this->deliveryFee($fulfilment, $country));
    }

    /** What one guest pays to join an aṣọ-ẹbí group. */
    public function quoteShare(User $guest, AsoEbiGroup $group, int $yards): array
    {
        $this->sweep();
        $fabric = Fabric::query()->with('vendor')->findOrFail($group->fabric_id);

        return $this->pricing->quote([$this->shareLine($guest, $group, $fabric, $yards)], AsoEbiRules::guestFulfilment($group->fulfilment));
    }

    /** Housekeeping that also runs from cron: lapse unpaid checkouts, stale offers, quiet deliveries. */
    public function sweep(): void
    {
        $this->releaseExpired();
        $this->offers->expireStale();
        $this->autoCompleteDelivered();
    }

    /**
     * @param  list<array{fabric_id:int, quantity:int, price_offer_id?:int|null}>  $items
     * @param  array{name:string, phone:string, email:string}  $contact
     * @param  array<string,string>|null  $address
     */
    public function create(User $buyer, array $items, string $fulfilment, array $contact, ?array $address, int $expectedTotalMinor, string $callbackUrl, ?string $country = null, ?string $arrivalDate = null): Checkout
    {
        $this->assertPayments();
        $this->sweep();
        $fee = $this->deliveryFee($fulfilment, $country);
        // Starting again (e.g. after backing out of the Paystack page) replaces any earlier unpaid
        // cart checkout, so its stock and agreed prices are free for this one.
        foreach (Checkout::query()->where('customer_id', $buyer->id)->whereNull('aso_ebi_group_id')->where('payment_status', Checkout::PENDING)->get() as $old) {
            $this->cancel($old, 'customer', 'Replaced by a newer checkout.', $buyer);
        }

        $checkout = DB::transaction(function () use ($buyer, $items, $fulfilment, $contact, $address, $expectedTotalMinor, $fee, $country, $arrivalDate) {
            // Lock in id order so two checkouts for the same fabrics cannot deadlock.
            $ids = array_values(array_unique(array_map('intval', array_column($items, 'fabric_id'))));
            sort($ids);
            $fabrics = Fabric::query()->with('vendor')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            $quote = $this->pricing->quote($this->lines($buyer, $items, $fabrics, forCheckout: true), $fulfilment, $fee);

            return $this->persist($buyer, $quote, $fulfilment, $contact, $address, $expectedTotalMinor, [
                'ship_country' => $fulfilment === 'international' ? strtoupper((string) $country) : null,
                'arrival_date' => $fulfilment === 'arrival' ? $arrivalDate : null,
            ]);
        });

        return $this->startPayment($checkout, $buyer, $callbackUrl);
    }

    /** One guest pays their share of an aṣọ-ẹbí group. */
    public function createForShare(User $guest, AsoEbiGroup $group, int $yards, bool $showName, array $contact, ?array $address, int $expectedTotalMinor, string $callbackUrl): Checkout
    {
        $this->assertPayments();
        $this->sweep();
        foreach (Checkout::query()->where('customer_id', $guest->id)->where('aso_ebi_group_id', $group->id)->where('payment_status', Checkout::PENDING)->get() as $old) {
            $this->cancel($old, 'customer', 'Replaced by a newer payment for the same aṣọ-ẹbí.', $guest);
        }

        $checkout = DB::transaction(function () use ($guest, $group, $yards, $showName, $contact, $address, $expectedTotalMinor) {
            // Lock the group first, so two guests cannot both claim the last of the bulk price.
            $group = AsoEbiGroup::query()->lockForUpdate()->findOrFail($group->id);
            $fabric = Fabric::query()->with('vendor')->lockForUpdate()->findOrFail($group->fabric_id);
            $this->asoEbi->assertCanJoin($group->status, \DateTimeImmutable::createFromMutable($group->deadline_at->toDateTime()), new \DateTimeImmutable, $yards, (int) $fabric->min_order_quantity);

            $fulfilment = AsoEbiRules::guestFulfilment($group->fulfilment);
            $quote = $this->pricing->quote([$this->shareLine($guest, $group, $fabric, $yards)], $fulfilment);
            $checkout = $this->persist($guest, $quote, $fulfilment, $contact, $fulfilment === 'delivery' ? $address : null, $expectedTotalMinor, ['aso_ebi_group_id' => $group->id]);

            $share = new AsoEbiShare(['yards' => $yards, 'show_name' => $showName]);
            $share->aso_ebi_group_id = $group->id;
            $share->guest_id = $guest->id;
            $share->checkout_id = $checkout->id;
            $share->status = 'pending';
            $share->save();

            return $checkout;
        });

        return $this->startPayment($checkout, $guest, $callbackUrl);
    }

    private function assertPayments(): void
    {
        if (! $this->gateway->enabled()) {
            throw new DomainRuleViolation('Online payment is not switched on yet. Please check back soon.', 'payments_disabled');
        }
    }

    /**
     * Write the checkout, one order per vendor and their lines, and hold the stock.
     *
     * @param  array<string,mixed>  $quote
     * @param  array<string,mixed>  $extra
     */
    private function persist(User $buyer, array $quote, string $fulfilment, array $contact, ?array $address, int $expectedTotalMinor, array $extra): Checkout
    {
        if (! $quote['ok']) {
            throw new DomainRuleViolation($quote['blocking'][0]['message'] ?? 'Your cart needs attention.', 'cart_invalid');
        }
        if ($quote['total'] !== $expectedTotalMinor) {
            throw new DomainRuleViolation('Prices or delivery changed since you looked. Review the new total before paying.', 'cart_changed');
        }

        $checkout = new Checkout;
        $checkout->forceFill($extra);
        $checkout->reference = $this->newReference();
        $checkout->customer_id = $buyer->id;
        $checkout->fulfilment = $fulfilment;
        $checkout->contact = $contact;
        $checkout->address = $fulfilment === 'pickup' ? null : $address;
        $checkout->subtotal_minor = $quote['subtotal'];
        $checkout->delivery_minor = $quote['delivery'];
        $checkout->service_fee_minor = $quote['service_fee'];
        $checkout->total_minor = $quote['total'];
        $checkout->currency = $this->pricing->currency;
        $checkout->payment_status = Checkout::PENDING;
        $checkout->expires_at = now()->addMinutes((int) config('marketplace.shop.checkout_hold_minutes', 60));
        $checkout->save();

        $fees = CartPricing::allocate($quote['service_fee'], array_column($quote['vendors'], 'subtotal'));
        $commissionBps = (int) config('marketplace.shop.vendor_commission_bps', 500);

        foreach ($quote['vendors'] as $i => $v) {
            $order = new FabricOrder;
            $order->reference = 'FAB-'.strtoupper(Str::random(8));
            $order->checkout_id = $checkout->id;
            $order->customer_id = $buyer->id;
            $order->fabric_vendor_id = $v['vendor_id'];
            $order->aso_ebi_group_id = $extra['aso_ebi_group_id'] ?? null;
            $order->status = FabricOrderStatus::PENDING_PAYMENT;
            $order->fulfilment = $fulfilment;
            $order->subtotal_minor = $v['subtotal'];
            $order->delivery_minor = $v['delivery'];
            $order->service_fee_minor = $fees[$i];
            $order->total_minor = $v['subtotal'] + $v['delivery'] + $fees[$i];
            $order->vendor_commission_minor = intdiv($v['subtotal'] * $commissionBps + 5_000, 10_000);
            $order->save();
            $order->events()->create(['actor_id' => $buyer->id, 'actor_role' => 'customer', 'from_status' => null, 'to_status' => FabricOrderStatus::PENDING_PAYMENT, 'note' => null]);

            foreach ($v['lines'] as $line) {
                $order->items()->create([
                    'fabric_id' => $line['fabric_id'], 'price_offer_id' => $line['price_offer_id'],
                    'name' => $line['name'], 'unit' => $line['unit'], 'quantity' => $line['quantity'],
                    'list_unit_minor' => $line['list_unit_minor'], 'unit_minor' => $line['unit_minor'],
                    'line_total_minor' => $line['line_total_minor'],
                ]);
                // Hold the stock while the buyer pays.
                Fabric::query()->whereKey($line['fabric_id'])->increment('reserved_quantity', $line['quantity']);
            }
        }

        return $checkout;
    }

    private function startPayment(Checkout $checkout, User $buyer, string $callbackUrl): Checkout
    {
        try {
            $url = $this->gateway->initialize(
                reference: $checkout->reference,
                amountMinor: $checkout->total_minor,
                currency: $checkout->currency,
                email: $buyer->email,
                callbackUrl: $callbackUrl,
                metadata: ['checkout' => $checkout->reference, 'orders' => $checkout->orders()->pluck('reference')->all()],
            );
        } catch (\Throwable $e) {
            // The payment page never opened: give the stock straight back.
            $this->cancel($checkout, 'system', 'Payment could not be started.');
            throw $e instanceof DomainRuleViolation ? $e : new DomainRuleViolation('Payment could not be started. Please try again.', 'payment_start_failed');
        }

        $checkout->authorization_url = $url;
        $checkout->save();

        return $checkout;
    }

    /** Ask the provider about a payment and record it if it went through. Safe to call any number of times. */
    public function verify(Checkout $checkout): Checkout
    {
        if ($checkout->payment_status === Checkout::PAID || $checkout->payment_status === Checkout::NEEDS_REFUND) {
            return $checkout;
        }
        $charge = $this->gateway->verify($checkout->reference);
        if (($charge['status'] ?? null) !== 'success') {
            return $checkout->fresh();
        }

        return $this->confirmPaid($checkout->reference, $charge);
    }

    /**
     * Record a successful charge. Idempotent: a webhook and a verify call racing each other,
     * or a replayed webhook, mark the checkout paid exactly once.
     *
     * @param  array<string,mixed>  $charge
     */
    public function confirmPaid(string $reference, array $charge): Checkout
    {
        return DB::transaction(function () use ($reference, $charge) {
            $checkout = Checkout::query()->where('reference', $reference)->lockForUpdate()->firstOrFail();
            if (in_array($checkout->payment_status, [Checkout::PAID, Checkout::NEEDS_REFUND], true)) {
                return $checkout;
            }
            ChargeCheck::assertPays($charge, $checkout->reference, $checkout->total_minor, $checkout->currency);

            $orders = $checkout->orders()->with('items')->lockForUpdate()->get();
            $items = $orders->flatMap->items;
            $stillHeld = $checkout->payment_status === Checkout::PENDING;

            $ids = $items->pluck('fabric_id')->unique()->sort()->values()->all();
            $fabrics = Fabric::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

            if (! $stillHeld) {
                // The hold lapsed before the money arrived. Take the stock again if it is still there.
                $short = $items->first(fn ($it) => ($fabrics[$it->fabric_id]?->availableQuantity() ?? 0) < $it->quantity);
                if ($short !== null) {
                    $checkout->payment_status = Checkout::NEEDS_REFUND;
                    $checkout->amount_paid_minor = (int) $charge['amount'];
                    $checkout->paid_at = now();
                    $checkout->save();
                    foreach ($orders as $order) {
                        $this->moveOrder($order, FabricOrderStatus::CANCELLED, null, 'system', 'Paid after the stock hold ended and the fabric had sold out. A refund is due.', force: true);
                    }
                    AsoEbiShare::query()->where('checkout_id', $checkout->id)->update(['status' => 'cancelled']);
                    Log::warning('Checkout paid after stock ran out; refund needed', ['reference' => $reference]);

                    return $checkout;
                }
                foreach ($items as $it) {
                    $fabrics[$it->fabric_id]->increment('reserved_quantity', $it->quantity);
                }
            }

            foreach ($items as $it) {
                // Reserved stock becomes sold stock.
                Fabric::query()->whereKey($it->fabric_id)->update([
                    'stock_quantity' => DB::raw('stock_quantity - '.(int) $it->quantity),
                    'reserved_quantity' => DB::raw('reserved_quantity - '.(int) $it->quantity),
                ]);
                if ($it->price_offer_id !== null) {
                    $offer = PriceOffer::query()->lockForUpdate()->find($it->price_offer_id);
                    if ($offer !== null && PriceOfferStatus::can($offer->status, PriceOfferStatus::USED, 'system')) {
                        $offer->status = PriceOfferStatus::USED;
                        $offer->used_at = now();
                        $offer->save();
                    }
                }
            }

            $checkout->payment_status = Checkout::PAID;
            $checkout->amount_paid_minor = (int) $charge['amount'];
            $checkout->paid_at = now();
            $checkout->save();

            foreach ($orders as $order) {
                $this->moveOrder($order, FabricOrderStatus::PAID, null, 'system', 'Payment confirmed by Paystack.', force: $order->status !== FabricOrderStatus::PENDING_PAYMENT);
                $order->loadMissing('vendor', 'items');
                $this->notifier->notify($order->vendor?->user_id, 'order_paid', 'New paid order '.$order->reference,
                    $order->items->map(fn ($i) => "{$i->quantity} × {$i->name}")->implode(', ').'. Pack it and mark it packed.', '/vendor?tab=orders');
            }
            $this->notifier->notify($checkout->customer_id, 'payment_confirmed', 'Payment confirmed', 'We received '.\App\Domain\Money\Money::minor($checkout->total_minor)->format().'. Your vendor is packing your fabric.', '/shop/orders');

            $share = AsoEbiShare::query()->where('checkout_id', $checkout->id)->lockForUpdate()->first();
            if ($share !== null && $share->status !== 'paid') {
                $share->status = 'paid';
                $share->save();
                $group = AsoEbiGroup::query()->find($share->aso_ebi_group_id);
                if ($group !== null) {
                    $paid = $group->shares()->where('status', 'paid')->count();
                    $this->notifier->notify($group->organizer_id, 'aso_ebi_paid', "{$paid} of {$group->target_guests} paid for {$group->title}",
                        ($share->show_name ? (strtok((string) $checkout->contact['name'], ' ') ?: 'A guest') : 'A guest')." paid for {$share->yards} yards.", '/aso-ebi/'.$group->code);
                }
            }

            return $checkout;
        });
    }

    /** Buyer gives up before paying, or the system abandons a checkout. Releases held stock. */
    public function cancel(Checkout $checkout, string $role, string $reason, ?User $actor = null): Checkout
    {
        return DB::transaction(function () use ($checkout, $role, $reason, $actor) {
            $checkout = Checkout::query()->lockForUpdate()->findOrFail($checkout->id);
            if ($checkout->payment_status !== Checkout::PENDING) {
                return $checkout;
            }
            $this->release($checkout);
            $checkout->payment_status = $role === 'system' ? Checkout::EXPIRED : Checkout::CANCELLED;
            $checkout->save();
            foreach ($checkout->orders()->lockForUpdate()->get() as $order) {
                $this->moveOrder($order, FabricOrderStatus::CANCELLED, $actor, $role, $reason);
            }
            AsoEbiShare::query()->where('checkout_id', $checkout->id)->where('status', 'pending')->update(['status' => 'cancelled']);

            return $checkout;
        });
    }

    /** Release stock held by checkouts whose payment window has passed. */
    public function releaseExpired(): int
    {
        $n = 0;
        foreach (Checkout::query()->where('payment_status', Checkout::PENDING)->where('expires_at', '<', now())->limit(100)->get() as $c) {
            $this->cancel($c, 'system', 'Payment was not completed in time.');
            $n++;
        }

        return $n;
    }

    /** Vendor or buyer moves an order along. */
    public function transition(User $user, FabricOrder $order, string $to, ?string $note): FabricOrder
    {
        return DB::transaction(function () use ($user, $order, $to, $note) {
            $order = FabricOrder::query()->with('vendor')->lockForUpdate()->findOrFail($order->id);
            $role = match (true) {
                $user->isAdmin() => 'admin',
                $order->vendor?->user_id === $user->id => 'vendor',
                $order->customer_id === $user->id => 'customer',
                default => throw new DomainRuleViolation('This order is not yours.', 'forbidden'),
            };
            $dispatch = [FabricOrderStatus::OUT_FOR_DELIVERY, FabricOrderStatus::READY_FOR_PICKUP];
            if (in_array($to, $dispatch, true) && $to !== FabricOrderStatus::dispatchStep($order->fulfilment)) {
                throw new DomainRuleViolation($order->fulfilment === 'pickup'
                    ? 'The buyer is collecting this order. Mark it ready for pickup.'
                    : 'The buyer chose delivery. Mark it out for delivery.', 'wrong_dispatch_step');
            }
            if ($to === FabricOrderStatus::CANCELLED && $role === 'customer') {
                throw new DomainRuleViolation('To cancel a paid order, contact OSAPS support.', 'cancel_needs_support');
            }
            $this->moveOrder($order, $to, $user, $role, $note);
            if ($role === 'vendor') {
                $what = match ($to) {
                    FabricOrderStatus::PACKED => 'Your fabric is packed.',
                    FabricOrderStatus::OUT_FOR_DELIVERY => 'Your fabric is on its way.',
                    FabricOrderStatus::READY_FOR_PICKUP => 'Your fabric is ready to collect.',
                    FabricOrderStatus::DELIVERED => 'The vendor marked your fabric as delivered. Confirm when you have it.',
                    default => null,
                };
                if ($what !== null) {
                    $this->notifier->notify($order->customer_id, 'order_update', $what, 'Order '.$order->reference.' from '.$order->vendor?->business_name.'.', '/shop/orders/'.$order->reference);
                }
            }
            if ($to === FabricOrderStatus::COMPLETED) {
                $this->notifier->notify($order->vendor?->user_id, 'payout_releasable', 'Order '.$order->reference.' completed', 'The buyer confirmed receipt. Your payout is released for payment.', '/vendor?tab=orders');
            }

            return $order->fresh(['items', 'events', 'vendor', 'checkout']);
        });
    }

    private function moveOrder(FabricOrder $order, string $to, ?User $actor, string $role, ?string $note, bool $force = false): void
    {
        $from = $order->status;
        if ($from === $to) {
            return;
        }
        if (! $force) {
            FabricOrderStatus::assert($from, $to, $role);
        }
        $order->status = $to;
        if ($to === FabricOrderStatus::DELIVERED) {
            $order->delivered_at = now();
        }
        if ($to === FabricOrderStatus::COMPLETED) {
            $order->completed_at = now();
        }
        if ($order->payout_status !== PayoutRules::PAID) {
            $order->payout_status = PayoutRules::forStatus($to);
        }
        $order->save();
        $order->events()->create(['actor_id' => $actor?->id, 'actor_role' => $role, 'from_status' => $from, 'to_status' => $to, 'note' => $note]);
    }

    /** Complete orders the buyer has not confirmed some days after delivery, releasing the vendor's payout. */
    public function autoCompleteDelivered(): int
    {
        $n = 0;
        $cutoff = now()->subDays($this->payouts->autoCompleteDays);
        $due = FabricOrder::query()->where('status', FabricOrderStatus::DELIVERED)->where('delivered_at', '<=', $cutoff)->limit(100)->pluck('id');
        foreach ($due as $id) {
            DB::transaction(function () use ($id, &$n) {
                $order = FabricOrder::query()->with('vendor')->lockForUpdate()->find($id);
                $deliveredAt = $order?->delivered_at ? \DateTimeImmutable::createFromMutable($order->delivered_at->toDateTime()) : null;
                if ($order === null || ! $this->payouts->shouldAutoComplete($order->status, $deliveredAt, new \DateTimeImmutable)) {
                    return;
                }
                $this->moveOrder($order, FabricOrderStatus::COMPLETED, null, 'system', "Completed automatically {$this->payouts->autoCompleteDays} days after delivery.");
                $this->notifier->notify($order->vendor?->user_id, 'payout_releasable', 'Order '.$order->reference.' completed', 'No problems reported since delivery. Your payout is released for payment.', '/vendor?tab=orders');
                $n++;
            });
        }

        return $n;
    }

    /** The single line a guest buys when paying their aṣọ-ẹbí share, at the group's bulk price if it still applies. */
    private function shareLine(User $guest, AsoEbiGroup $group, Fabric $fabric, int $yards): CartLine
    {
        $committed = (int) $group->shares()->whereIn('status', ['paid', 'pending'])->sum('yards');
        $deal = $this->asoEbi->dealApplies($group->deal_unit_minor, $group->deal_quantity, $committed, $yards) ? $group->deal_unit_minor : null;

        return new CartLine(
            fabricId: $fabric->id,
            vendorId: (int) $fabric->fabric_vendor_id,
            vendorName: (string) ($fabric->vendor?->business_name ?? 'Vendor'),
            fabricName: $fabric->name,
            unit: $fabric->unit,
            quantity: $yards,
            listUnitMinor: (int) $fabric->price_minor,
            available: $fabric->availableQuantity(),
            minQuantity: (int) $fabric->min_order_quantity,
            listed: $fabric->status === 'active' && $fabric->vendor?->user_id !== $guest->id,
            fixedUnitMinor: $deal,
        );
    }

    private function release(Checkout $checkout): void
    {
        $items = $checkout->orders()->with('items')->get()->flatMap->items;
        $ids = $items->pluck('fabric_id')->unique()->sort()->values()->all();
        Fabric::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        foreach ($items as $it) {
            Fabric::query()->whereKey($it->fabric_id)->update([
                'reserved_quantity' => DB::raw('CASE WHEN reserved_quantity >= '.(int) $it->quantity.' THEN reserved_quantity - '.(int) $it->quantity.' ELSE 0 END'),
            ]);
        }
    }

    /**
     * @param  list<array{fabric_id:int, quantity:int, price_offer_id?:int|null}>  $items
     * @param  Collection<int, Fabric>  $fabrics
     * @return list<CartLine>
     */
    private function lines(User $buyer, array $items, Collection $fabrics, bool $forCheckout = false): array
    {
        $offerIds = array_values(array_filter(array_map(fn ($i) => isset($i['price_offer_id']) ? (int) $i['price_offer_id'] : null, $items)));
        $offers = $offerIds === [] ? collect() : PriceOffer::query()->whereIn('id', $offerIds)->when($forCheckout, fn ($q) => $q->lockForUpdate())->get()->keyBy('id');

        // An agreed price may back only one unpaid checkout at a time. The buyer's own unpaid
        // checkout does not count: creating a new one cancels it first.
        $busy = $offerIds === [] ? [] : \App\Models\FabricOrderItem::query()
            ->whereIn('price_offer_id', $offerIds)
            ->whereHas('fabricOrder', fn ($q) => $q->where('status', FabricOrderStatus::PENDING_PAYMENT)->where('customer_id', '!=', $buyer->id))
            ->pluck('price_offer_id')->all();

        $lines = [];
        foreach ($items as $item) {
            $fabric = $fabrics[(int) $item['fabric_id']] ?? null;
            $offerId = isset($item['price_offer_id']) ? (int) $item['price_offer_id'] : null;
            $offer = $offerId !== null ? ($offers[$offerId] ?? null) : null;
            $usable = $offer !== null && $fabric !== null
                && $offer->buyer_id === $buyer->id
                && $offer->fabric_id === $fabric->id
                && $offer->isUsable()
                && ! in_array($offer->id, $busy, true);

            $lines[] = new CartLine(
                fabricId: (int) $item['fabric_id'],
                vendorId: (int) ($fabric?->fabric_vendor_id ?? 0),
                vendorName: (string) ($fabric?->vendor?->business_name ?? 'Unknown vendor'),
                fabricName: (string) ($fabric?->name ?? 'A fabric'),
                unit: (string) ($fabric?->unit ?? 'unit'),
                quantity: (int) $item['quantity'],
                listUnitMinor: (int) ($fabric?->price_minor ?? 0),
                available: $fabric?->availableQuantity() ?? 0,
                minQuantity: (int) ($fabric?->min_order_quantity ?? 1),
                listed: $fabric !== null && $fabric->status === 'active' && $fabric->vendor?->user_id !== $buyer->id,
                offerId: $offerId,
                offerUnitMinor: $offer?->unit_minor,
                offerQuantity: $offer?->quantity,
                offerUsable: $usable,
            );
        }

        return $lines;
    }

    private function newReference(): string
    {
        do {
            $ref = 'OSP-'.strtoupper(Str::random(16));
            $ref = preg_replace('/[^A-Z0-9-]/', 'X', $ref);
        } while (Checkout::query()->where('reference', $ref)->exists());

        return $ref;
    }
}
