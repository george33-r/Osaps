<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_requests', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('customer_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 140);
            $table->string('garment_category', 32)->index();
            $table->text('description');
            $table->json('style_tags')->nullable();
            // budget: customer names a price | open: tailors quote freely
            $table->string('mode', 12);
            $table->unsignedBigInteger('budget_min_minor')->nullable();
            $table->unsignedBigInteger('budget_max_minor')->nullable();
            // own_fabric | buying_on_platform | need_advice
            $table->string('fabric_status', 24);
            $table->foreignId('fabric_id')->nullable()->constrained()->nullOnDelete();
            $table->string('city', 80)->index();
            $table->date('needed_by');
            $table->boolean('fitting_required')->default(true);
            $table->string('status', 16)->default('open')->index();
            $table->timestamp('offers_close_at')->index();
            $table->unsignedBigInteger('awarded_offer_id')->nullable();
            $table->timestamps();

            $table->index(['status', 'garment_category', 'city']);
        });

        Schema::create('offers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tailor_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 20)->index();
            $table->unsignedBigInteger('amount_minor');
            $table->boolean('at_customer_budget')->default(false);
            $table->unsignedSmallInteger('delivery_days')->nullable();
            $table->boolean('includes_fitting')->nullable();
            $table->unsignedTinyInteger('alterations_included')->nullable();
            $table->unsignedBigInteger('delivery_fee_minor')->nullable();
            $table->text('message')->nullable();
            $table->unsignedTinyInteger('tailor_rounds')->default(0);
            $table->unsignedTinyInteger('customer_rounds')->default(0);
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            // One negotiation thread per tailor per request.
            $table->unique(['job_request_id', 'tailor_id']);
            $table->index(['tailor_id', 'status']);
        });

        // Append-only history of every price/terms proposal in a thread.
        Schema::create('offer_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->string('author_role', 12); // tailor | customer
            $table->foreignId('author_id')->constrained('users');
            $table->unsignedBigInteger('amount_minor');
            $table->unsignedSmallInteger('delivery_days')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offer_revisions');
        Schema::dropIfExists('offers');
        Schema::dropIfExists('job_requests');
    }
};
