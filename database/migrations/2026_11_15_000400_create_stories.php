<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Home page stories: written by tailors (after an admin approves them) or by admins (published at once). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('author_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('tailor_profile_id')->nullable()->constrained('tailor_profiles')->nullOnDelete();
            $table->string('kind', 12)->default('story');
            $table->string('title', 80);
            $table->text('body');
            $table->string('link_url', 255)->nullable();
            $table->string('photo_path', 255)->nullable();
            $table->string('status', 12)->default('pending')->index();
            $table->string('hidden_reason', 300)->nullable();
            $table->timestamp('published_at')->nullable()->index();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stories');
    }
};
