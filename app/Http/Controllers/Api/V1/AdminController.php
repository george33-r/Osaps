<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Payments\RefundRules;
use App\Domain\Shop\DisputeRules;
use App\Domain\Shop\FabricOrderStatus;
use App\Domain\Shop\PayoutRules;
use App\Domain\Shop\VendorVerification;
use App\Http\Controllers\Controller;
use App\Http\Resources\Concerns\FormatsMoney;
use App\Models\AdminAction;
use App\Models\Checkout;
use App\Models\FabricOrder;
use App\Models\FabricVendor;
use App\Models\Refund;
use App\Services\TrustService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The OSAPS back office: shops to verify, problem reports, refunds owed, payouts (two admins),
 * orders that seem stuck, and the log of what admins did. Every route here is admin-only
 * (the "admin" middleware), and every decision is recorded in admin_actions.
 */
class AdminController extends Controller
{
    use FormatsMoney;

    public function __construct(private readonly TrustService $trust, private readonly PayoutRules $payouts) {}

    public function overview(): JsonResponse
    {
        return response()->json(['data' => [
            'vendors_pending' => FabricVendor::query()->where('verification_status', VendorVerification::PENDING)->count(),
            'disputes_open' => FabricOrder::query()->where('dispute_status', DisputeRules::OPEN)->count(),
            'refunds_owed' => $this->owedQuery()->count(),
            'refunds_unconfirmed' => Refund::query()->where('status', RefundRules::PENDING)->whereNotNull('failure')->count(),
            'payouts_releasable' => FabricOrder::query()->where('payout_status', PayoutRules::RELEASABLE)->count(),
            'payouts_approved' => FabricOrder::query()->where('payout_status', PayoutRules::APPROVED)->count(),
            'stuck' => $this->stuckQuery()->count(),
            'two_person_payouts' => $this->payouts->twoPersonPayouts,
            // Set every minute by the scheduler. Empty or old means the hPanel cron job is missing.
            'cron_last_run' => \Illuminate\Support\Facades\Cache::get('scheduler:last_run'),
            'queue' => config('queue.default'),
            'queued_jobs' => config('queue.default') === 'database' ? \Illuminate\Support\Facades\DB::table('jobs')->count() : null,
            'payments_enabled' => app(\App\Payments\PaymentGateway::class)->enabled(),
            'mail' => config('mail.default'),
        ]]);
    }

    // ---- Shops ---------------------------------------------------------------------------

    public function vendors(Request $request): JsonResponse
    {
        $status = $request->query('status', VendorVerification::PENDING);
        abort_unless(in_array($status, array_keys(VendorVerification::LABELS), true), 422);
        $vendors = FabricVendor::query()->with('user:id,name,email,phone,created_at')->withCount('fabrics')
            ->where('verification_status', $status)->orderBy('verification_requested_at')->limit(200)->get();

        return response()->json(['data' => $vendors->map(fn (FabricVendor $v) => [
            'id' => $v->id, 'business_name' => $v->business_name, 'city' => $v->city, 'market' => $v->market,
            'phone' => $v->phone, 'whatsapp' => $v->whatsapp,
            'owner' => ['name' => $v->user?->name, 'email' => $v->user?->email, 'joined' => $v->user?->created_at?->toDateString()],
            'bank' => ['name' => $v->bank_name, 'account_name' => $v->bank_account_name,
                // Shown in full to admins so they can check it matches the business name.
                'account_number' => $v->bank_account_number],
            'fabrics' => $v->fabrics_count,
            'status' => $v->verification_status, 'label' => VendorVerification::LABELS[$v->verification_status] ?? $v->verification_status,
            'note' => $v->verification_note,
            'requested_at' => $v->verification_requested_at?->toIso8601String(),
            'is_demo' => (bool) $v->is_demo,
        ])->values()]);
    }

    public function reviewVendor(Request $request, FabricVendor $fabricVendor): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', Rule::in([VendorVerification::VERIFIED, VendorVerification::REJECTED, VendorVerification::SUSPENDED])],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $v = $this->trust->reviewVendor($request->user(), $fabricVendor, $data['to'], $data['note'] ?? null);

        return response()->json(['data' => ['id' => $v->id, 'status' => $v->verification_status]]);
    }

    // ---- Problem reports -----------------------------------------------------------------

    public function disputes(): JsonResponse
    {
        $orders = FabricOrder::query()->with('vendor', 'checkout', 'items')->where('dispute_status', DisputeRules::OPEN)->orderBy('dispute_opened_at')->limit(200)->get();

        return response()->json(['data' => $orders->map(fn ($o) => $this->orderRow($o) + [
            'reason' => DisputeRules::REASONS[$o->dispute_reason] ?? $o->dispute_reason,
            'details' => $o->dispute_details,
            'opened_at' => $o->dispute_opened_at?->toIso8601String(),
        ])->values()]);
    }

    public function resolveDispute(Request $request, FabricOrder $fabricOrder): JsonResponse
    {
        $data = $request->validate([
            'resolution' => ['required', Rule::in(DisputeRules::RESOLUTIONS)],
            'note' => ['required', 'string', 'min:5', 'max:1000'],
            'refund_minor' => ['nullable', 'integer', 'min:100'],
        ]);
        $o = $this->trust->resolveDispute($request->user(), $fabricOrder, $data['resolution'], $data['note'], $data['refund_minor'] ?? null);

        return response()->json(['data' => $this->orderRow($o)]);
    }

    // ---- Refunds -------------------------------------------------------------------------

    public function refunds(): JsonResponse
    {
        $owed = $this->owedQuery()->with('vendor', 'checkout', 'items', 'refunds')->orderBy('updated_at')->limit(200)->get();
        $recent = Refund::query()->with('order:id,reference', 'checkout:id,reference')->latest('id')->limit(50)->get();

        return response()->json(['data' => [
            'owed' => $owed->map(fn ($o) => $this->orderRow($o) + [
                'remaining' => self::money(RefundRules::remaining($o->total_minor, (int) $o->refunds->whereIn('status', [RefundRules::PENDING, RefundRules::PROCESSED])->sum('amount_minor'))),
                'checkout_status' => $o->checkout?->payment_status,
            ])->values(),
            'recent' => $recent->map(fn (Refund $r) => [
                'id' => $r->id, 'order' => $r->order?->reference, 'checkout' => $r->checkout?->reference,
                'amount' => self::money($r->amount_minor), 'status' => $r->status, 'label' => RefundRules::LABELS[$r->status] ?? $r->status,
                'failure' => $r->failure, 'reason' => $r->reason, 'at' => $r->created_at?->toIso8601String(),
            ])->values(),
        ]]);
    }

    public function refund(Request $request, FabricOrder $fabricOrder): JsonResponse
    {
        $data = $request->validate([
            'amount_minor' => ['nullable', 'integer', 'min:100'],
            'reason' => ['required', 'string', 'min:5', 'max:300'],
        ]);
        $r = $this->trust->refund($request->user(), $fabricOrder, $data['amount_minor'] ?? null, $data['reason']);

        return response()->json(['data' => ['id' => $r->id, 'status' => $r->status, 'failure' => $r->failure]], 201);
    }

    public function markRefundFailed(Request $request, Refund $refund): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'min:5', 'max:300']]);
        $r = $this->trust->markRefundFailed($request->user(), $refund, $data['note']);

        return response()->json(['data' => ['id' => $r->id, 'status' => $r->status]]);
    }

    // ---- Payouts -------------------------------------------------------------------------

    public function payouts(Request $request): JsonResponse
    {
        $orders = FabricOrder::query()->with('vendor')
            ->whereIn('payout_status', [PayoutRules::RELEASABLE, PayoutRules::APPROVED])
            ->orderBy('completed_at')->limit(300)->get();
        $me = $request->user()->id;

        return response()->json(['data' => $orders->groupBy('fabric_vendor_id')->map(function ($list) use ($me) {
            $v = $list->first()->vendor;

            return [
                'vendor' => [
                    'id' => $v?->id,
                    'business_name' => $v?->business_name,
                    'verified' => (bool) $v?->isVerified(),
                    'bank_name' => $v?->bank_name,
                    'bank_account_name' => $v?->bank_account_name,
                    'bank_account_number' => $v?->bank_account_number, // decrypted for admins only
                ],
                'amount' => self::money($list->sum(fn ($o) => $this->trust->payoutAmount($o))),
                'orders' => $list->map(fn ($o) => [
                    'reference' => $o->reference, 'completed_at' => $o->completed_at?->toIso8601String(),
                    'amount' => self::money($this->trust->payoutAmount($o)),
                    'payout_status' => $o->payout_status,
                    'approved_by_me' => $o->payout_approved_by === $me,
                    'approved_at' => $o->payout_approved_at?->toIso8601String(),
                ])->values(),
            ];
        })->values(), 'meta' => ['two_person_payouts' => $this->payouts->twoPersonPayouts]]);
    }

    public function approvePayout(Request $request, FabricOrder $fabricOrder): JsonResponse
    {
        $o = $this->trust->approvePayout($request->user(), $fabricOrder);

        return response()->json(['data' => ['reference' => $o->reference, 'payout_status' => $o->payout_status]]);
    }

    public function markPaid(Request $request, FabricOrder $fabricOrder): JsonResponse
    {
        $data = $request->validate(['transfer_reference' => ['required', 'string', 'min:4', 'max:80']]);
        $o = $this->trust->markPaidOut($request->user(), $fabricOrder, $data['transfer_reference']);

        return response()->json(['data' => ['reference' => $o->reference, 'payout_status' => $o->payout_status]]);
    }

    // ---- Stuck orders and the log --------------------------------------------------------

    /** Paid orders with no confirmed handover for a long time: chase the vendor, or confirm or cancel after checking. */
    public function stuck(): JsonResponse
    {
        $orders = $this->stuckQuery()->with('vendor', 'checkout', 'items')->orderBy('updated_at')->limit(200)->get();

        return response()->json(['data' => $orders->map(fn ($o) => $this->orderRow($o) + ['since' => $o->updated_at?->toIso8601String()])->values()]);
    }

    public function actions(): JsonResponse
    {
        $rows = AdminAction::query()->with('admin:id,name')->latest('id')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn ($a) => [
            'id' => $a->id, 'admin' => $a->admin?->name, 'action' => $a->action, 'subject' => $a->subject_type.' #'.$a->subject_id,
            'note' => $a->note, 'at' => $a->created_at?->toIso8601String(),
        ])->values()]);
    }

    private function owedQuery()
    {
        return FabricOrder::query()->where('status', FabricOrderStatus::CANCELLED)
            ->whereNotIn('payout_status', [PayoutRules::PAID, PayoutRules::APPROVED])
            ->whereHas('checkout', fn ($q) => $q->whereIn('payment_status', [Checkout::PAID, Checkout::NEEDS_REFUND]))
            ->whereRaw('total_minor > (select coalesce(sum(amount_minor), 0) from refunds where refunds.fabric_order_id = fabric_orders.id and refunds.status in (?, ?))', [RefundRules::PENDING, RefundRules::PROCESSED]);
    }

    private function stuckQuery()
    {
        $days = (int) config('marketplace.shop.stuck_after_days', 7);

        return FabricOrder::query()
            ->whereIn('status', [FabricOrderStatus::PAID, FabricOrderStatus::PACKED, FabricOrderStatus::OUT_FOR_DELIVERY, FabricOrderStatus::READY_FOR_PICKUP])
            ->where('updated_at', '<', now()->subDays($days));
    }

    /** @return array<string,mixed> */
    private function orderRow(FabricOrder $o): array
    {
        return [
            'reference' => $o->reference,
            'status' => $o->status,
            'status_label' => FabricOrderStatus::LABELS[$o->status] ?? $o->status,
            'fulfilment' => $o->fulfilment,
            'total' => self::money($o->total_minor),
            'vendor' => $o->relationLoaded('vendor') ? ['business_name' => $o->vendor?->business_name, 'phone' => $o->vendor?->phone] : null,
            'buyer' => $o->relationLoaded('checkout') ? ['name' => $o->checkout?->contact['name'] ?? null, 'phone' => $o->checkout?->contact['phone'] ?? null, 'email' => $o->checkout?->contact['email'] ?? null] : null,
            'items' => $o->relationLoaded('items') ? $o->items->map(fn ($i) => "{$i->quantity} × {$i->name}")->implode(', ') : null,
            'payout_status' => $o->payout_status,
            'created_at' => $o->created_at?->toIso8601String(),
        ];
    }
}
