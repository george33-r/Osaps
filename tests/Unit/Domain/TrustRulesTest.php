<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Payments\RefundRules;
use App\Domain\Shop\DeliveryCode;
use App\Domain\Shop\DisputeRules;
use App\Domain\Shop\FabricOrderStatus;
use App\Domain\Shop\PayoutRules;
use App\Domain\Shop\VendorVerification;
use App\Domain\Support\DomainRuleViolation;
use PHPUnit\Framework\TestCase;

/** Buyer protection: delivery proof, problem reports, refunds, vendor checks and two-person payouts. */
final class TrustRulesTest extends TestCase
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

    public function test_delivery_code_is_six_digits_and_must_match(): void
    {
        for ($i = 0; $i < 50; $i++) {
            $this->assertSame(1, preg_match('/\A\d{6}\z/', DeliveryCode::generate()));
        }
        $this->assertSame('none', $this->code(fn () => DeliveryCode::assertMatches('042917', ' 042 917 ', 0)));
        $this->assertSame('delivery_code_wrong', $this->code(fn () => DeliveryCode::assertMatches('042917', '042918', 0)));
        $this->assertSame('delivery_code_required', $this->code(fn () => DeliveryCode::assertMatches('042917', 'abc', 0)));
        $this->assertSame('delivery_code_missing', $this->code(fn () => DeliveryCode::assertMatches(null, '042917', 0)));
        // Locked after 5 wrong tries, even with the right code: no guessing a million codes.
        $this->assertSame('delivery_code_locked', $this->code(fn () => DeliveryCode::assertMatches('042917', '042917', 5)));
    }

    public function test_vendor_can_no_longer_complete_an_order_on_its_own(): void
    {
        $this->assertFalse(FabricOrderStatus::can('delivered', 'completed', 'vendor'));
        $this->assertTrue(FabricOrderStatus::can('delivered', 'completed', 'customer'));
        $this->assertTrue(FabricOrderStatus::can('out_for_delivery', 'cancelled', 'admin'));
        $this->assertTrue(FabricOrderStatus::can('delivered', 'cancelled', 'admin'));
        $this->assertFalse(FabricOrderStatus::can('completed', 'cancelled', 'admin'));
    }

    public function test_dispute_rules(): void
    {
        $this->assertTrue(DisputeRules::canOpen('paid', null));
        $this->assertTrue(DisputeRules::canOpen('delivered', null));
        $this->assertFalse(DisputeRules::canOpen('completed', null));
        $this->assertFalse(DisputeRules::canOpen('delivered', 'released'));
        $this->assertSame('none', $this->code(fn () => DisputeRules::assertCanOpen('out_for_delivery', null, 'not_received', 'Rider never came.')));
        $this->assertSame('dispute_exists', $this->code(fn () => DisputeRules::assertCanOpen('paid', 'open', 'other', 'Something is wrong here')));
        $this->assertSame('dispute_not_allowed', $this->code(fn () => DisputeRules::assertCanOpen('completed', null, 'other', 'Something is wrong here')));
        $this->assertSame('dispute_not_allowed', $this->code(fn () => DisputeRules::assertCanOpen('pending_payment', null, 'other', 'Something is wrong here')));
        $this->assertSame('dispute_reason', $this->code(fn () => DisputeRules::assertCanOpen('paid', null, 'bored', 'Something is wrong here')));
        $this->assertSame('dispute_details', $this->code(fn () => DisputeRules::assertCanOpen('paid', null, 'other', 'bad')));
        $this->assertSame('dispute_open', $this->code(fn () => DisputeRules::assertNotBlocking('open', 'completed')));
        $this->assertSame('none', $this->code(fn () => DisputeRules::assertNotBlocking('open', 'cancelled')));
        $this->assertSame('none', $this->code(fn () => DisputeRules::assertCanResolve('open', 'refund', 'Vendor confirmed it was not sent.')));
        $this->assertSame('dispute_not_open', $this->code(fn () => DisputeRules::assertCanResolve('refunded', 'refund', 'Again')));
        $this->assertSame('dispute_note_required', $this->code(fn () => DisputeRules::assertCanResolve('open', 'release', '')));
    }

    public function test_refund_rules(): void
    {
        $this->assertSame(150_000, RefundRules::remaining(500_000, 350_000));
        $this->assertSame(0, RefundRules::remaining(500_000, 600_000));
        $ok = fn (string $st = 'cancelled', bool $paid = true, string $payout = 'withheld', int $amt = 500_000, int $rem = 500_000) => $this->code(fn () => RefundRules::assertCanRefund($st, $paid, $payout, $amt, $rem));
        $this->assertSame('none', $ok());
        $this->assertSame('refund_not_paid', $ok(paid: false));
        $this->assertSame('refund_order_not_cancelled', $ok(st: 'delivered'));
        $this->assertSame('refund_payout_sent', $ok(payout: 'paid'));
        $this->assertSame('refund_payout_sent', $ok(payout: 'approved'));
        $this->assertSame('refund_done', $ok(rem: 0));
        $this->assertSame('refund_amount', $ok(amt: 500_001));
        $this->assertSame('refund_amount', $ok(amt: 0));
        $this->assertSame('processed', RefundRules::fromProvider('processed'));
        $this->assertSame('pending', RefundRules::fromProvider('needs-attention'));
    }

    public function test_vendor_verification(): void
    {
        $this->assertTrue(VendorVerification::canSell('verified'));
        $this->assertFalse(VendorVerification::canSell('pending'));
        $this->assertFalse(VendorVerification::canSell('suspended'));
        $this->assertFalse(VendorVerification::canSell(null));
        $this->assertTrue(VendorVerification::can('unverified', 'pending', 'vendor'));
        $this->assertFalse(VendorVerification::can('unverified', 'verified', 'vendor'));
        $this->assertFalse(VendorVerification::can('pending', 'verified', 'vendor'));
        $this->assertTrue(VendorVerification::can('pending', 'verified', 'admin'));
        $this->assertTrue(VendorVerification::can('verified', 'suspended', 'admin'));
        $full = ['business_name' => 'Ada Fabrics', 'city' => 'Lagos', 'market' => 'Balogun', 'phone' => '08030000000', 'bank_name' => 'GTBank', 'bank_account_name' => 'Ada Fabrics', 'bank_account_number' => '0123456789'];
        $this->assertSame([], VendorVerification::missingForReview($full));
        $this->assertSame(['phone', 'bank_account_number'], VendorVerification::missingForReview(['phone' => ' ', 'bank_account_number' => '12345'] + $full));
        $this->assertSame('verification_note_required', $this->code(fn () => VendorVerification::assertNoteGiven('rejected', '')));
        $this->assertSame('none', $this->code(fn () => VendorVerification::assertNoteGiven('verified', null)));
    }

    public function test_payout_needs_verified_vendor_and_two_admins(): void
    {
        $p = new PayoutRules;
        $this->assertSame('none', $this->code(fn () => $p->assertCanApprove('releasable', true, false, true)));
        $this->assertSame('payout_not_releasable', $this->code(fn () => $p->assertCanApprove('held', true, false, true)));
        $this->assertSame('payout_vendor_unverified', $this->code(fn () => $p->assertCanApprove('releasable', false, false, true)));
        $this->assertSame('payout_dispute_open', $this->code(fn () => $p->assertCanApprove('releasable', true, true, true)));
        $this->assertSame('payout_no_bank', $this->code(fn () => $p->assertCanApprove('releasable', true, false, false)));
        $this->assertSame('payout_not_approved', $this->code(fn () => $p->assertCanMarkPaid('releasable', null, 7)));
        $this->assertSame('payout_needs_second_admin', $this->code(fn () => $p->assertCanMarkPaid('approved', 7, 7)));
        $this->assertSame('none', $this->code(fn () => $p->assertCanMarkPaid('approved', 7, 8)));
        $single = new PayoutRules(twoPersonPayouts: false);
        $this->assertSame('none', $this->code(fn () => $single->assertCanMarkPaid('releasable', null, 7)));
        $this->assertSame('payout_not_releasable', $this->code(fn () => $single->assertCanMarkPaid('held', null, 7)));
    }
}
