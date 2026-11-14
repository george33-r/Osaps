<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tailor of the week, saved stories, customer questions for tailors, and circle-shaped moments. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('story_saves', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('story_id')->constrained('stories')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'story_id']);
        });

        Schema::create('tailor_features', function (Blueprint $table) {
            $table->id();
            $table->date('week_start')->unique();
            $table->foreignId('tailor_profile_id')->constrained('tailor_profiles')->cascadeOnDelete();
            $table->string('source', 8);
            $table->string('reason', 300)->nullable();
            $table->foreignId('chosen_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('asker_id')->constrained('users')->cascadeOnDelete();
            $table->string('question', 300);
            // pending (waiting for an OSAPS check) -> open -> answered -> published, or hidden
            $table->string('status', 12)->default('pending')->index();
            $table->foreignId('tailor_profile_id')->nullable()->constrained('tailor_profiles')->nullOnDelete();
            $table->string('answer', 600)->nullable();
            $table->timestamp('answered_at')->nullable();
            $table->foreignId('story_id')->nullable()->constrained('stories')->nullOnDelete();
            $table->string('hidden_reason', 300)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('moments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('tailor_profile_id')->nullable()->constrained('tailor_profiles')->nullOnDelete();
            $table->string('media_type', 8);
            $table->string('media_path', 255);
            $table->string('mime', 40);
            $table->string('caption', 140)->nullable();
            $table->string('status', 12)->default('pending')->index();
            $table->string('hidden_reason', 300)->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('moments');
        Schema::dropIfExists('questions');
        Schema::dropIfExists('tailor_features');
        Schema::dropIfExists('story_saves');
    }
};
