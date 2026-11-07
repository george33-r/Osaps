<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tailor_profiles', function (Blueprint $table) {
            $table->text('verification_note')->nullable();
            $table->timestamp('verification_requested_at')->nullable();
            $table->timestamp('verification_consent_at')->nullable();
        });

        Schema::create('tailor_verification_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tailor_profile_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('path');
            $table->string('mime', 32);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('status', 16)->default('submitted')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 300)->nullable();
            $table->timestamps();
            $table->index(['tailor_profile_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tailor_verification_documents');
        Schema::table('tailor_profiles', function (Blueprint $table) {
            $table->dropColumn(['verification_note', 'verification_requested_at', 'verification_consent_at']);
        });
    }
};
