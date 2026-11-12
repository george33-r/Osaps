<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fabric_vendors', function (Blueprint $table) {
            $table->timestamp('verification_consent_at')->nullable();
        });

        Schema::create('vendor_verification_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fabric_vendor_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 32);
            $table->string('path');
            $table->string('mime', 32);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('status', 16)->default('submitted')->index();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 300)->nullable();
            $table->timestamps();
            $table->index(['fabric_vendor_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_verification_documents');
        Schema::table('fabric_vendors', function (Blueprint $table) {
            $table->dropColumn('verification_consent_at');
        });
    }
};
