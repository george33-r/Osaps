<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tailor orders get a payment record (one pending attempt at a time) and a completion time.
 * Existing completed orders get their completion time from the order's history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('completed_at')->nullable()->after('due_on');
        });

        DB::table('orders')->where('status', 'completed')->whereNull('completed_at')->update([
            'completed_at' => DB::raw("(select max(order_events.created_at) from order_events where order_events.order_id = orders.id and order_events.to_status = 'completed')"),
        ]);

        Schema::create('order_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('reference', 20)->unique();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 3)->default('NGN');
            $table->string('status', 12)->default('pending')->index();
            $table->unsignedBigInteger('amount_paid_minor')->nullable();
            $table->string('authorization_url', 500)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payments');
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('completed_at');
        });
    }
};
