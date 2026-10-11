<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 20)->unique();
            // Unique: a request can be awarded once; an offer can become one order. DB-level backstop
            // against double-acceptance races in addition to the row lock in NegotiationService.
            $table->foreignId('job_request_id')->unique()->constrained();
            $table->foreignId('offer_id')->unique()->constrained();
            $table->foreignId('customer_id')->constrained('users');
            $table->foreignId('tailor_id')->constrained('users');
            $table->string('status', 16)->index();
            // Locked copy of the agreed scope and price at acceptance. Never edited in place;
            // scope changes (later phase) are recorded as new, mutually approved versions.
            $table->json('spec_snapshot');
            $table->unsignedSmallInteger('spec_version')->default(1);
            $table->unsignedBigInteger('tailoring_minor');
            $table->unsignedBigInteger('delivery_fee_minor')->default(0);
            $table->unsignedBigInteger('service_fee_minor');
            $table->unsignedBigInteger('total_minor');
            $table->unsignedBigInteger('tailor_commission_minor');
            // Payment is a separate machine, not yet integrated: unpaid until a verified provider webhook says otherwise.
            $table->string('payment_status', 16)->default('unpaid')->index();
            $table->date('due_on')->nullable();
            $table->timestamps();

            $table->index(['customer_id', 'status']);
            $table->index(['tailor_id', 'status']);
        });

        // Append-only audit trail of every status change.
        Schema::create('order_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users');
            $table->string('actor_role', 16);
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16);
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_events');
        Schema::dropIfExists('orders');
    }
};
