<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tailor_profiles', fn (Blueprint $t) => $t->timestamp('recheck_reminded_at')->nullable());
        Schema::table('fabric_vendors', fn (Blueprint $t) => $t->timestamp('recheck_reminded_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('tailor_profiles', fn (Blueprint $t) => $t->dropColumn('recheck_reminded_at'));
        Schema::table('fabric_vendors', fn (Blueprint $t) => $t->dropColumn('recheck_reminded_at'));
    }
};
