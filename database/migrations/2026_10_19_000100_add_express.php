<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Express: faster fabric delivery for a higher fee, and tailoring requests marked as urgent. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('checkouts', function (Blueprint $table) {
            $table->boolean('express')->default(false)->after('fulfilment');
        });
        Schema::table('fabric_orders', function (Blueprint $table) {
            $table->boolean('express')->default(false)->after('fulfilment');
        });
        Schema::table('job_requests', function (Blueprint $table) {
            $table->boolean('is_express')->default(false)->after('fitting_required');
        });
    }

    public function down(): void
    {
        Schema::table('job_requests', fn (Blueprint $t) => $t->dropColumn('is_express'));
        Schema::table('fabric_orders', fn (Blueprint $t) => $t->dropColumn('express'));
        Schema::table('checkouts', fn (Blueprint $t) => $t->dropColumn('express'));
    }
};
