<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Customer reviews of tailors. One review per order (enforced by the database), with the
 * original text and every correction kept, photos held privately until an admin approves them,
 * reports, moderation and appeals.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tailor_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users');
            $table->foreignId('tailor_id')->constrained('users');
            $table->unsignedTinyInteger('stars');
            $table->unsignedTinyInteger('quality')->nullable();
            $table->unsignedTinyInteger('communication')->nullable();
            $table->unsignedTinyInteger('delivery_time')->nullable();
            $table->unsignedTinyInteger('value_for_money')->nullable();
            $table->text('body')->nullable();
            $table->string('status', 12)->default('published');
            $table->string('hidden_reason', 300)->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('corrected_at')->nullable();
            $table->unsignedTinyInteger('correction_count')->default(0);
            $table->timestamps();

            $table->index(['tailor_id', 'status']);
        });

        Schema::create('tailor_review_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('tailor_reviews')->cascadeOnDelete();
            $table->unsignedTinyInteger('version');
            $table->unsignedTinyInteger('stars');
            $table->unsignedTinyInteger('quality')->nullable();
            $table->unsignedTinyInteger('communication')->nullable();
            $table->unsignedTinyInteger('delivery_time')->nullable();
            $table->unsignedTinyInteger('value_for_money')->nullable();
            $table->text('body')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['review_id', 'version']);
        });

        // One tailor reply per review. Never deleted, even if the customer corrects the review later.
        Schema::create('tailor_review_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->unique()->constrained('tailor_reviews')->cascadeOnDelete();
            $table->foreignId('tailor_id')->constrained('users');
            $table->unsignedTinyInteger('responded_to_version');
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('tailor_review_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('tailor_reviews')->cascadeOnDelete();
            $table->string('path', 255);
            $table->string('mime', 40);
            $table->unsignedBigInteger('size_bytes');
            $table->string('status', 12)->default('pending')->index();
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('tailor_review_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('tailor_reviews')->cascadeOnDelete();
            $table->foreignId('reporter_id')->constrained('users');
            $table->string('reason', 40);
            $table->text('details')->nullable();
            $table->string('status', 12)->default('open')->index();
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->unique(['review_id', 'reporter_id']);
        });

        Schema::create('tailor_review_appeals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained('tailor_reviews')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('users');
            $table->text('reason');
            $table->string('status', 12)->default('open')->index();
            $table->foreignId('decided_by')->nullable()->constrained('users');
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tailor_review_appeals');
        Schema::dropIfExists('tailor_review_reports');
        Schema::dropIfExists('tailor_review_photos');
        Schema::dropIfExists('tailor_review_responses');
        Schema::dropIfExists('tailor_review_revisions');
        Schema::dropIfExists('tailor_reviews');
    }
};
