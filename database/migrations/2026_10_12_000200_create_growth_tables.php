<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aṣọ-ẹbí group orders, vendor media, held payouts, notifications (in-app and WhatsApp),
 * diaspora delivery, saved measurements, live market sessions and try-on usage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aso_ebi_groups', function (Blueprint $table) {
            $table->id();
            $table->string('code', 10)->unique();
            $table->foreignId('organizer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('fabric_id')->constrained();
            $table->string('title', 80);
            $table->string('message', 500)->nullable();
            $table->date('event_date');
            $table->timestamp('deadline_at')->index();
            $table->unsignedSmallInteger('yards_per_guest');
            $table->unsignedSmallInteger('target_guests');
            // Bulk price agreed with the vendor (from an accepted price offer), valid up to deal_quantity.
            $table->unsignedBigInteger('deal_unit_minor')->nullable();
            $table->unsignedInteger('deal_quantity')->nullable();
            $table->foreignId('price_offer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('fulfilment', 20); // deliver_each | pickup_organizer
            $table->string('organizer_phone', 32);
            $table->string('status', 12)->default('open')->index();
            $table->timestamps();
        });

        Schema::create('aso_ebi_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aso_ebi_group_id')->constrained()->cascadeOnDelete();
            $table->foreignId('guest_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedSmallInteger('yards');
            $table->boolean('show_name')->default(true);
            $table->foreignId('checkout_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('status', 12)->default('pending')->index(); // pending | paid | cancelled
            $table->timestamps();
            $table->index(['aso_ebi_group_id', 'status']);
        });

        Schema::table('checkouts', function (Blueprint $table) {
            $table->foreignId('aso_ebi_group_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
            $table->date('arrival_date')->nullable()->after('address');
            $table->string('ship_country', 2)->nullable()->after('arrival_date');
        });

        Schema::table('fabric_orders', function (Blueprint $table) {
            $table->foreignId('aso_ebi_group_id')->nullable()->after('fabric_vendor_id')->constrained()->nullOnDelete();
            // held | releasable | paid | withheld — the vendor is paid only after the buyer confirms receipt.
            $table->string('payout_status', 12)->default('held')->index()->after('vendor_commission_minor');
            $table->timestamp('delivered_at')->nullable()->after('payout_status');
            $table->timestamp('completed_at')->nullable()->after('delivered_at');
            $table->timestamp('paid_out_at')->nullable()->after('completed_at');
        });

        Schema::create('fabric_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fabric_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 8); // photo | video
            $table->string('path');
            $table->string('mime', 40);
            $table->unsignedInteger('size_bytes');
            $table->unsignedTinyInteger('sort')->default(0);
            $table->timestamps();
            $table->index(['fabric_id', 'sort']);
        });

        Schema::table('fabric_vendors', function (Blueprint $table) {
            // For manual payouts. The account number is encrypted at rest (see FabricVendor casts).
            $table->string('bank_name', 80)->nullable();
            $table->text('bank_account_number')->nullable();
            $table->string('bank_account_name', 120)->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('whatsapp_opt_in')->default(false);
            $table->string('currency', 3)->nullable();
        });

        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('title', 120);
            $table->string('body', 300)->nullable();
            $table->string('link', 200)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'read_at']);
        });

        Schema::create('user_measurements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('unit', 2)->default('cm'); // how the person prefers to see them; stored in cm
            $table->json('values');
            $table->string('notes', 500)->nullable();
            $table->boolean('share_with_tailors')->default(true);
            $table->timestamps();
        });

        Schema::create('live_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fabric_vendor_id')->constrained()->cascadeOnDelete();
            $table->string('title', 100);
            $table->string('platform', 12);
            $table->string('url', 300);
            $table->timestamp('starts_at')->index();
            $table->timestamp('ends_at')->nullable();
            $table->json('fabric_ids')->nullable();
            $table->timestamps();
        });

        // Try-on: who asked and when (for daily limits). Photos are never stored.
        Schema::create('try_on_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fabric_id')->constrained()->cascadeOnDelete();
            $table->string('result', 16);
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('try_on_requests');
        Schema::dropIfExists('live_sessions');
        Schema::dropIfExists('user_measurements');
        Schema::dropIfExists('user_notifications');
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['whatsapp_opt_in', 'currency']));
        Schema::table('fabric_vendors', fn (Blueprint $t) => $t->dropColumn(['bank_name', 'bank_account_number', 'bank_account_name']));
        Schema::dropIfExists('fabric_media');
        Schema::table('fabric_orders', function (Blueprint $t) {
            $t->dropConstrainedForeignId('aso_ebi_group_id');
            $t->dropColumn(['payout_status', 'delivered_at', 'completed_at', 'paid_out_at']);
        });
        Schema::table('checkouts', function (Blueprint $t) {
            $t->dropConstrainedForeignId('aso_ebi_group_id');
            $t->dropColumn(['arrival_date', 'ship_country']);
        });
        Schema::dropIfExists('aso_ebi_shares');
        Schema::dropIfExists('aso_ebi_groups');
    }
};
