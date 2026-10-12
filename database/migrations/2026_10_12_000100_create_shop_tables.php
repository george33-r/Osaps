<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fabric-only shopping: inDrive-style price offers, checkouts paid through Paystack,
 * one order per vendor, and an idempotent log of payment provider events.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fabric_id')->constrained()->cascadeOnDelete();
            $table->foreignId('buyer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('fabric_vendor_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            // Listing price when the offer was opened (the ceiling for every counter).
            $table->unsignedBigInteger('list_unit_minor');
            // Price currently on the table, per unit, and each side's latest.
            $table->unsignedBigInteger('unit_minor');
            $table->unsignedBigInteger('buyer_unit_minor');
            $table->unsignedBigInteger('vendor_unit_minor')->nullable();
            $table->string('status', 16)->index();
            $table->unsignedTinyInteger('buyer_rounds')->default(0);
            $table->unsignedTinyInteger('vendor_rounds')->default(0);
            $table->string('note', 200)->nullable();
            $table->timestamp('respond_by')->nullable();
            $table->timestamp('hold_until')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['buyer_id', 'status']);
            $table->index(['fabric_vendor_id', 'status']);
            $table->index(['fabric_id', 'buyer_id', 'status']);
        });

        Schema::create('price_offer_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_offer_id')->constrained()->cascadeOnDelete();
            $table->string('author_role', 8); // buyer | vendor | system
            $table->foreignId('author_id')->nullable()->constrained('users');
            $table->string('action', 12); // open | counter | accept | decline | withdraw | expire
            $table->unsignedBigInteger('unit_minor')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('checkouts', function (Blueprint $table) {
            $table->id();
            // Also the Paystack transaction reference.
            $table->string('reference', 24)->unique();
            $table->foreignId('customer_id')->constrained('users');
            $table->string('fulfilment', 12); // delivery | pickup
            $table->json('contact');
            $table->json('address')->nullable();
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('delivery_minor');
            $table->unsignedBigInteger('service_fee_minor');
            $table->unsignedBigInteger('total_minor');
            $table->string('currency', 3)->default('NGN');
            // pending | paid | expired | cancelled | needs_refund
            $table->string('payment_status', 16)->default('pending')->index();
            $table->string('provider', 16)->default('paystack');
            $table->text('authorization_url')->nullable();
            $table->unsignedBigInteger('amount_paid_minor')->nullable();
            $table->timestamp('paid_at')->nullable();
            // Stock is held until this moment; after it the reservation is released.
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->index(['customer_id', 'payment_status']);
        });

        Schema::create('fabric_orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            $table->foreignId('checkout_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users');
            $table->foreignId('fabric_vendor_id')->constrained();
            $table->string('status', 20)->index();
            $table->string('fulfilment', 12);
            $table->unsignedBigInteger('subtotal_minor');
            $table->unsignedBigInteger('delivery_minor');
            $table->unsignedBigInteger('service_fee_minor');
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('vendor_commission_minor');
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['fabric_vendor_id', 'status']);
        });

        Schema::create('fabric_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fabric_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fabric_id')->constrained();
            $table->foreignId('price_offer_id')->nullable()->constrained()->nullOnDelete();
            // Snapshot at checkout: later listing edits never change what was bought.
            $table->string('name');
            $table->string('unit', 12);
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('list_unit_minor');
            $table->unsignedBigInteger('unit_minor');
            $table->unsignedBigInteger('line_total_minor');
        });

        Schema::create('fabric_order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fabric_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->string('actor_role', 16);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        // Every provider callback we receive, once. The unique key makes webhook replays harmless.
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 16);
            $table->string('event_key', 64)->unique();
            $table->string('reference', 64)->nullable()->index();
            $table->string('event', 48);
            $table->boolean('signature_valid');
            $table->string('result', 32)->nullable();
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('fabric_order_events');
        Schema::dropIfExists('fabric_order_items');
        Schema::dropIfExists('fabric_orders');
        Schema::dropIfExists('checkouts');
        Schema::dropIfExists('price_offer_rounds');
        Schema::dropIfExists('price_offers');
    }
};
