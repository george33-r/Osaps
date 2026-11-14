<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Email updates are off until a person turns them on. Every announcement is recorded with its recipient count. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('email_updates_opt_in')->default(false)->after('whatsapp_opt_in');
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users');
            $table->string('audience', 12);
            $table->string('subject', 150);
            $table->text('body');
            $table->foreignId('story_id')->nullable()->constrained('stories')->nullOnDelete();
            $table->unsignedInteger('recipients')->default(0);
            $table->timestamp('sent_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcements');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('email_updates_opt_in');
        });
    }
};
