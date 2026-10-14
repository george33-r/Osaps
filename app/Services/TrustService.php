<?php

namespace App\Services;

use App\Domain\Payments\RefundRules;
use App\Domain\Shop\DisputeRules;
use App\Domain\Shop\FabricOrderStatus;
use App\Domain\Shop\PayoutRules;
use App\Domain\Shop\VendorVerification;
use App\Domain\Support\DomainRuleViolation;
use App\Models\AdminAction;
use App\Models\Checkout;
use App\Models\FabricOrder;
use App\Models\FabricVendor;
use App\Models\Refund;
use App\Models\User;
use App\Payments\PaymentGateway;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Buyer protection and the back office: problem reports, refunds, vendor checks and payouts.
 * Every admin decision is written to admin_actions.
 */
class TrustService
{
    public function __construct(
        private readonly CheckoutService $checkouts,
        private readonly PaymentGateway $gateway,
        private readonly Notifier $notifier,
        private readonly PayoutRules $payouts,
    ) {}

    // ---- Problem reports -------------------------------------------------------------------

    public function openDispute(User $buyer, FabricOrder $order, string $reason, string $details): FabricOrder
    {
        return DB::transaction(function () use ($buyer, $order, $reason, $details) {
            $order = FabricOrder::query()->with('vendor')->lockForUpdate()->findOrFail($order->id);
            abort_unless($order->customer_id === $buyer->id, 403);
            DisputeRules::assertCanOpen($order->status, $order->dispute_status, $reason, $details);
            $order->dispute_status = DisputeRules::OPEN;
            $order->dispute_reason = $reason;
            $order->dispute_details = mb_substr(trim($details), 0, 2000);
            $order->dispute_opened_at = now();
            $order->save();
            $order->events()->create(['actor_id' => $buyer->id, 'actor_role' => 'customer', 'from_status' => $order->status, 'to_status' => $order->status,
                'note' => 'Problem reported: '.DisputeRules::REASONS[$reason].'.']);

            $this->notifier->notify($order->vendor?->user_id, 'dispute_opened', 'Problem reported on '.$order->reference,
                DisputeRules::REASONS[$reason].'. OSAPS support will contact you. The payout is on hold until it is settled.', '/vendor?tab=orders');
            $this->notifyAdmins('Problem report: '.$order->reference, DisputeRules::REASONS[$reason], '/admin?tab=disputes');

            return $order;
        });
    }

    /** Settle a report: refund the buyer (order cancelled, money returned) or release it to the vendor (order completes). */
    public function resolveDispute(User $admin, FabricOrder $order, string $resolution, string $note, ?int $refundMinor = null): FabricOrder
    {
        $order = DB::transaction(function () use ($admin, $order, $resolution, $note) {
            $order = FabricOrder::query()->with('vendor')->lockForUpdate()->findOrFail($order->id);
            DisputeRules::assertCanResolve($order->dispute_status, $resolution, $note);
            $order->dispute_status = $resolution === 'refund' ? DisputeRules::REFUNDED : DisputeRules::RELEASED;
            $order->dispute_resolved_at = now();
            $order->dispute_resolution_note = mb_substr(trim($note), 0, 2000);
            $order->save();

            if ($resolution === 'refund') {
                $this->checkouts->moveOrder($order, FabricOrderStatus::CANCELLED, $admin, 'admin', 'Problem report settled with a refund: '.$note);
            } else {
                $order->delivered_by ??= 'admin';
                $this->checkouts->moveOrder($order, FabricOrderStatus::COMPLETED, $admin, 'admin', 'Problem report settled in the vendor\'s favour: '.$note, force: true);
            }
            AdminAction::record($admin, 'dispute_'.$resolution, $order, $note);

            $buyerText = $resolution === 'refund' ? 'We are refunding you. '.$note : 'We checked with both sides and closed the report. '.$note;
            $this->notifier->notify($order->customer_id, 'dispute_resolved', 'Your problem report on '.$order->reference.' is settled', $buyerText, '/shop/orders/'.$order->reference);
            $this->notifier->notify($order->vendor?->user_id, 'dispute_resolved', 'Problem report on '.$order->reference.' settled',
                $resolution === 'refund' ? 'The buyer is being refunded; this order is not payable. '.$note : 'Settled in your favour. Your payout is ready. '.$note, '/vendor?tab=orders');

            return $order;
        });

        if ($resolution === 'refund') {
            $this->refund($admin, $order, $refundMinor, 'Problem report: '.$note);
        }

        return $order->fresh(['items', 'events', 'vendor', 'checkout', 'refunds']);
    }

    // ---- Refunds ---------------------------------------------------------------------------

    /**
     * Send money back through Paystack. The refund row is saved first, then Paystack is called.
     * If Paystack does not answer, the refund stays pending and is flagged for a dashboard check:
     * it is never retried automatically, so a buyer is never refunded twice.
     */
    public function refund(User $admin, FabricOrder $order, ?int $amountMinor, string $reason): Refund
    {
        $refund = DB::transaction(function () use ($admin, $order, $amountMinor, $reason) {
            $order = FabricOrder::query()->lockForUpdate()->findOrFail($order->id);
            $checkout = Checkout::query()->lockForUpdate()->findOrFail($order->checkout_id);
            $remaining = RefundRules::remaining($order->total_minor, $order->committedRefundMinor());
            $amount = $amountMinor ?? $remaining;
            RefundRules::assertCanRefund($order->status, in_array($checkout->payment_status, [Checkout::PAID, Checkout::NEEDS_REFUND], true),
                (string) $order->payout_status, $amount, $remaining);

            $refund = Refund::query()->create([
                'checkout_id' => $checkout->id, 'fabric_order_id' => $order->id, 'amount_minor' => $amount, 'currency' => $checkout->currency,
                'status' => RefundRules::PENDING, 'reason' => mb_substr($reason, 0, 300), 'requested_by' => $admin->id,
            ]);
            AdminAction::record($admin, 'refund_requested', $order, $reason, ['amount_minor' => $amount, 'refund_id' => $refund->id]);

            return $refund;
        });

        $checkout = Checkout::query()->findOrFail($refund->checkout_id);
        $refund->load('order');
        try {
            $data = $this->gateway->refund($checkout->reference, $refund->amount_minor, $refund->currency, $reason.' ('.$refund->order?->reference.')');
            $refund->provider_refund_id = isset($data['id']) ? (string) $data['id'] : null;
            $refund->status = RefundRules::fromProvider(is_string($data['status'] ?? null) ? $data['status'] : null);
            $refund->processed_at = $refund->status === RefundRules::PROCESSED ? now() : null;
        } catch (DomainRuleViolation $e) {
            if ($e->rule === 'payment_outcome_unknown') {
                $refund->failure = 'Paystack did not answer. Check the Paystack dashboard, then mark this refund failed if nothing was sent.';
            } else {
                $refund->status = RefundRules::FAILED;
                $refund->failure = mb_substr($e->getMessage(), 0, 200);
            }
            Log::warning('Refund not confirmed by Paystack', ['refund' => $refund->id, 'rule' => $e->rule]);
        }
        $refund->save();

        if ($refund->status !== RefundRules::FAILED) {
            $this->notifier->notify($checkout->customer_id, 'refund', 'Refund started for '.$refund->order?->reference,
                \App\Domain\Money\Money::minor($refund->amount_minor)->format().' is on its way back to how you paid. Banks usually take a few working days.', '/shop/orders/'.$refund->order?->reference);
        }

        return $refund;
    }

    /** After checking the Paystack dashboard: nothing was sent, so the amount can be refunded again. */
    public function markRefundFailed(User $admin, Refund $refund, string $note): Refund
    {
        return DB::transaction(function () use ($admin, $refund, $note) {
            $refund = Refund::query()->lockForUpdate()->findOrFail($refund->id);
            if ($refund->status !== RefundRules::PENDING) {
                throw new DomainRuleViolation('Only a pending refund can be marked failed.', 'refund_not_pending');
            }
            if (mb_strlen(trim($note)) < 5) {
                throw new DomainRuleViolation('Note what you checked in the Paystack dashboard.', 'refund_note_required');
            }
            $refund->status = RefundRules::FAILED;
            $refund->failure = mb_substr('Marked failed by admin: '.$note, 0, 200);
            $refund->save();
            AdminAction::record($admin, 'refund_marked_failed', $refund, $note);

            return $refund;
        });
    }

    /** Paystack's refund.* webhook. Matched by Paystack's refund id, else by transaction and amount. */
    public function applyRefundEvent(string $event, array $data): string
    {
        $status = RefundRules::fromProvider(match ($event) {
            'refund.processed' => 'processed',
            'refund.failed' => 'failed',
            default => is_string($data['status'] ?? null) ? $data['status'] : null,
        });

        return DB::transaction(function () use ($data, $status) {
            $q = Refund::query()->lockForUpdate();
            $id = $data['id'] ?? ($data['refund_id'] ?? null);
            $refund = $id !== null ? (clone $q)->where('provider_refund_id', (string) $id)->first() : null;
            if ($refund === null) {
                $ref = $data['transaction_reference'] ?? ($data['transaction']['reference'] ?? null);
                $checkout = is_string($ref) ? Checkout::query()->where('reference', $ref)->first() : null;
                $refund = $checkout ? (clone $q)->where('checkout_id', $checkout->id)->where('status', RefundRules::PENDING)
                    ->where('amount_minor', (int) ($data['amount'] ?? 0))->orderBy('id')->first() : null;
            }
            if ($refund === null) {
                return 'refund_unmatched';
            }
            $refund->loadMissing('order');
            if ($refund->status === RefundRules::PROCESSED) {
                return 'refund_already_processed';
            }
            $refund->status = $status;
            if ($status === RefundRules::PROCESSED) {
                $refund->processed_at = now();
                $refund->failure = null;
            }
            if ($status === RefundRules::FAILED) {
                $refund->failure = 'Paystack reported the refund failed.';
                $this->notifyAdmins('Refund failed for '.$refund->order?->reference, 'Paystack could not complete it. Try again from the admin screen.', '/admin?tab=refunds');
            }
            $refund->save();

            return 'refund_'.$status;
        });
    }

    // ---- Vendor checks ---------------------------------------------------------------------

    public function requestReview(FabricVendor $vendor): FabricVendor
    {
        $status = $vendor->verification_status ?: VendorVerification::UNVERIFIED;
        if ($status === VendorVerification::PENDING || $status === VendorVerification::VERIFIED) {
            return $vendor;
        }
        VendorVerification::assertReadyForReview($vendor->only(['business_name', 'city', 'market', 'phone', 'bank_name', 'bank_account_name']) + ['bank_account_number' => $vendor->bank_account_number]);
        VendorVerification::assert($status, VendorVerification::PENDING, 'vendor');
        $vendor->verification_status = VendorVerification::PENDING;
        $vendor->verification_requested_at = now();
        $vendor->save();
        $this->notifyAdmins('Shop to verify: '.$vendor->business_name, $vendor->market.', '.$vendor->city, '/admin?tab=vendors');

        return $vendor;
    }

    public function reviewVendor(User $admin, FabricVendor $vendor, string $to, ?string $note): FabricVendor
    {
        return DB::transaction(function () use ($admin, $vendor, $to, $note) {
            $vendor = FabricVendor::query()->lockForUpdate()->findOrFail($vendor->id);
            VendorVerification::assertNoteGiven($to, $note);
            VendorVerification::assert($vendor->verification_status ?: VendorVerification::UNVERIFIED, $to, 'admin');
            $vendor->verification_status = $to;
            $vendor->verification_note = $note !== null ? mb_substr(trim($note), 0, 300) : null;
            $vendor->verified_at = $to === VendorVerification::VERIFIED ? now() : $vendor->verified_at;
            $vendor->save();
            AdminAction::record($admin, 'vendor_'.$to, $vendor, $note);

            $text = match ($to) {
                VendorVerification::VERIFIED => 'Your shop is verified. Your active listings are now visible to buyers.',
                VendorVerification::REJECTED => 'Your shop needs changes before it can go live: '.$note,
                VendorVerification::SUSPENDED => 'Your shop is suspended and hidden from buyers: '.$note,
                default => null,
            };
            $this->notifier->notify($vendor->user_id, 'vendor_review', 'Shop review: '.VendorVerification::LABELS[$to], $text, '/vendor?tab=shop');

            return $vendor;
        });
    }

    // ---- Payouts (two admins) --------------------------------------------------------------

    public function approvePayout(User $admin, FabricOrder $order): FabricOrder
    {
        return DB::transaction(function () use ($admin, $order) {
            $order = FabricOrder::query()->with('vendor')->lockForUpdate()->findOrFail($order->id);
            $this->payouts->assertCanApprove((string) $order->payout_status, (bool) $order->vendor?->isVerified(), DisputeRules::isOpen($order->dispute_status), (bool) $order->vendor?->hasBankDetails());
            $order->payout_status = PayoutRules::APPROVED;
            $order->payout_approved_by = $admin->id;
            $order->payout_approved_at = now();
            $order->save();
            AdminAction::record($admin, 'payout_approved', $order, null, ['amount_minor' => $this->payoutAmount($order)]);

            return $order;
        });
    }

    public function markPaidOut(User $admin, FabricOrder $order, string $transferReference): FabricOrder
    {
        return DB::transaction(function () use ($admin, $order, $transferReference) {
            $order = FabricOrder::query()->with('vendor')->lockForUpdate()->findOrFail($order->id);
            if (mb_strlen(trim($transferReference)) < 4) {
                throw new DomainRuleViolation('Enter the bank transfer reference so the payment can be traced.', 'payout_reference_required');
            }
            if (! $this->payouts->twoPersonPayouts) {
                $this->payouts->assertCanApprove((string) ($order->payout_status === PayoutRules::APPROVED ? PayoutRules::RELEASABLE : $order->payout_status),
                    (bool) $order->vendor?->isVerified(), DisputeRules::isOpen($order->dispute_status), (bool) $order->vendor?->hasBankDetails());
            }
            $this->payouts->assertCanMarkPaid((string) $order->payout_status, $order->payout_approved_by, $admin->id);
            $order->payout_status = PayoutRules::PAID;
            $order->paid_out_at = now();
            $order->payout_paid_by = $admin->id;
            $order->payout_transfer_reference = mb_substr(trim($transferReference), 0, 80);
            $order->save();
            $order->events()->create(['actor_id' => $admin->id, 'actor_role' => 'admin', 'from_status' => $order->status, 'to_status' => $order->status, 'note' => 'Vendor payout sent.']);
            AdminAction::record($admin, 'payout_paid', $order, $transferReference, ['amount_minor' => $this->payoutAmount($order)]);
            $this->notifier->notify($order->vendor?->user_id, 'payout_paid', 'Payout sent for '.$order->reference,
                \App\Domain\Money\Money::minor($this->payoutAmount($order))->format().' was sent to your bank account.', '/vendor?tab=orders');

            return $order;
        });
    }

    public function payoutAmount(FabricOrder $order): int
    {
        return $order->subtotal_minor + $order->delivery_minor - $order->vendor_commission_minor;
    }

    private function notifyAdmins(string $title, ?string $body, string $link): void
    {
        foreach (User::query()->where('role', User::ROLE_ADMIN)->pluck('id') as $id) {
            $this->notifier->notify((int) $id, 'admin', $title, $body, $link);
        }
    }
}
