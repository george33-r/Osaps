<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Accounts that already exist are treated as confirmed, so no current customer is locked out
 * of checkout. Accounts created after this point, through quick checkout, start unconfirmed.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->whereNull('email_verified_at')->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        // Not reversible: which accounts were confirmed before this migration is not recorded.
    }
};
