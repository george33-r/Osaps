<?php

use App\Domain\Verification\Recheck;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tailor_profiles', fn (Blueprint $t) => $t->timestamp('recheck_due_at')->nullable());
        Schema::table('fabric_vendors', fn (Blueprint $t) => $t->timestamp('recheck_due_at')->nullable());
        Schema::table('tailor_verification_documents', fn (Blueprint $t) => $t->date('expires_on')->nullable());
        Schema::table('vendor_verification_documents', fn (Blueprint $t) => $t->date('expires_on')->nullable());

        // Shops and tailors verified before this change get their first six-week check from today.
        $due = now()->addDays(Recheck::INTERVAL_DAYS);
        DB::table('tailor_profiles')->where('verification_status', 'verified')->whereNull('recheck_due_at')->update(['recheck_due_at' => $due]);
        DB::table('fabric_vendors')->where('verification_status', 'verified')->whereNull('recheck_due_at')->update(['recheck_due_at' => $due]);
    }

    public function down(): void
    {
        Schema::table('tailor_profiles', fn (Blueprint $t) => $t->dropColumn('recheck_due_at'));
        Schema::table('fabric_vendors', fn (Blueprint $t) => $t->dropColumn('recheck_due_at'));
        Schema::table('tailor_verification_documents', fn (Blueprint $t) => $t->dropColumn('expires_on'));
        Schema::table('vendor_verification_documents', fn (Blueprint $t) => $t->dropColumn('expires_on'));
    }
};
