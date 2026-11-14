<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Self-reported years of experience. Shown as the tailor's claim, never as a verified figure. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tailor_profiles', function (Blueprint $table) {
            $table->unsignedTinyInteger('experience_years')->nullable()->after('weekly_capacity');
        });
    }

    public function down(): void
    {
        Schema::table('tailor_profiles', function (Blueprint $table) {
            $table->dropColumn('experience_years');
        });
    }
};
