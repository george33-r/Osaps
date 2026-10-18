<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sample designs a customer attaches to a tailoring request, and photos in the customer–tailor
 * chat. Files are kept outside the web folder and served only to the people involved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_request_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_request_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('mime', 40);
            $table->unsignedInteger('size_bytes');
            $table->string('caption', 140)->nullable();
            $table->unsignedTinyInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::table('offer_messages', function (Blueprint $table) {
            $table->string('photo_path')->nullable();
            $table->string('photo_mime', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('offer_messages', fn (Blueprint $t) => $t->dropColumn(['photo_path', 'photo_mime']));
        Schema::dropIfExists('job_request_photos');
    }
};
