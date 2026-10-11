<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tailor_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('business_name');
            $table->string('slug')->unique();
            $table->text('bio')->nullable();
            $table->string('city', 80)->index();
            $table->json('service_cities');
            $table->json('specialties');
            $table->json('portfolio_tags')->nullable();
            $table->unsignedSmallInteger('weekly_capacity')->default(5);
            $table->unsignedBigInteger('starting_price_minor')->nullable();
            // unverified | pending | verified | rejected — only an admin review may set "verified".
            $table->string('verification_status', 16)->default('unverified')->index();
            $table->timestamp('verified_at')->nullable();
            $table->boolean('accepting_work')->default(true)->index();
            // Measured, not self-reported. Null until there is data.
            $table->unsignedInteger('median_response_minutes')->nullable();
            $table->boolean('is_demo')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('fabric_vendors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('business_name');
            $table->string('slug')->unique();
            $table->string('city', 80)->index();
            $table->string('market', 120)->nullable();
            $table->string('verification_status', 16)->default('unverified')->index();
            $table->timestamp('verified_at')->nullable();
            $table->boolean('is_demo')->default(false)->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fabric_vendors');
        Schema::dropIfExists('tailor_profiles');
    }
};
