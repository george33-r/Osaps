<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('job_requests', function (Blueprint $table) {
            $table->string('occasion', 32)->nullable()->after('garment_category');
        });

        // Vendor contact is shown only to signed-in users (FabricController::contact), never in public JSON.
        Schema::table('fabric_vendors', function (Blueprint $table) {
            $table->string('phone', 32)->nullable()->after('market');
            $table->string('whatsapp', 32)->nullable()->after('phone');
            $table->string('contact_note', 240)->nullable()->after('whatsapp');
        });

        // Questions and answers between a customer and one tailor, before and after acceptance.
        Schema::create('offer_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users');
            $table->string('author_role', 12); // customer | tailor
            $table->text('body');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['offer_id', 'id']);
        });

        // One row per AI call: purpose, prompt version, tokens, latency, outcome. Never prompts or personal data.
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('purpose', 40)->index();
            $table->string('provider', 20);
            $table->string('model', 60);
            $table->string('prompt_version', 40);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('latency_ms')->default(0);
            // ok | invalid_output | provider_error | timeout | unavailable
            $table->string('outcome', 20)->index();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
        Schema::dropIfExists('offer_messages');
        Schema::table('fabric_vendors', fn (Blueprint $t) => $t->dropColumn(['phone', 'whatsapp', 'contact_note']));
        Schema::table('job_requests', fn (Blueprint $t) => $t->dropColumn('occasion'));
    }
};
