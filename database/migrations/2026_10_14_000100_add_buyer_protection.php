<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Buyer protection and back office: delivery codes, problem reports, refunds, two-person vendor
 * payouts, vendor review notes, an admin action log, and carts saved to the account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fabric_orders', function (Blueprint $table) {
            $table->string('delivery_code', 6)->nullable();
            $table->unsignedTinyInteger('delivery_code_attempts')->default(0);
            $table->string('delivered_by', 12)->nullable(); // buyer | code | admin
            $table->string('dispute_status', 12)->nullable()->index(); // open | refunded | released
            $table->string('dispute_reason', 24)->nullable();
            $table->text('dispute_details')->nullable();
            $table->timestamp('dispute_opened_at')->nullable();
            $table->timestamp('dispute_resolved_at')->nullable();
            $table->text('dispute_resolution_note')->nullable();
            $table->foreignId('payout_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('payout_approved_at')->nullable();
            $table->foreignId('payout_paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payout_transfer_reference', 80)->nullable();
        });

        // Order history must survive: a checkout with orders can no longer be deleted by accident.
        Schema::table('fabric_orders', function (Blueprint $table) {
            $table->dropForeign(['checkout_id']);
            $table->foreign('checkout_id')->references('id')->on('checkouts')->restrictOnDelete();
        });

        Schema::table('fabric_vendors', function (Blueprint $table) {
            $table->string('verification_note', 300)->nullable();
            $table->timestamp('verification_requested_at')->nullable();
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checkout_id')->constrained()->restrictOnDelete();
            $table->foreignId('fabric_order_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('NGN');
            $table->string('status', 12)->index(); // pending | processed | failed
            $table->string('provider_refund_id', 64)->nullable()->index();
            $table->string('reason', 300);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('failure', 200)->nullable();
            $table->unsignedSmallInteger('attempts')->default(1);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('admin_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->constrained('users');
            $table->string('action', 40);
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->string('note', 500)->nullable();
            $table->json('data')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id']);
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fabric_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->foreignId('price_offer_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'fabric_id']);
        });

        // Paid orders still on their way get a delivery code, so the new handover rule applies to them too.
        DB::table('fabric_orders')->whereIn('status', ['paid', 'packed', 'out_for_delivery', 'ready_for_pickup'])->whereNull('delivery_code')
            ->orderBy('id')->each(function ($o) {
                DB::table('fabric_orders')->where('id', $o->id)->update(['delivery_code' => str_pad((string) random_int(0, 999_999), 6, '0', STR_PAD_LEFT)]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('admin_actions');
        Schema::dropIfExists('refunds');
        Schema::table('fabric_vendors', fn (Blueprint $t) => $t->dropColumn(['verification_note', 'verification_requested_at']));
        Schema::table('fabric_orders', function (Blueprint $table) {
            $table->dropForeign(['checkout_id']);
            $table->foreign('checkout_id')->references('id')->on('checkouts')->cascadeOnDelete();
            $table->dropConstrainedForeignId('payout_approved_by');
            $table->dropConstrainedForeignId('payout_paid_by');
            $table->dropColumn(['delivery_code', 'delivery_code_attempts', 'delivered_by', 'dispute_status', 'dispute_reason', 'dispute_details',
                'dispute_opened_at', 'dispute_resolved_at', 'dispute_resolution_note', 'payout_approved_at', 'payout_transfer_reference']);
        });
    }
};
