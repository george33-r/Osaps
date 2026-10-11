<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fabrics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fabric_vendor_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('category', 24)->index();
            $table->text('description')->nullable();
            $table->string('primary_color', 24)->index();
            $table->json('colors');
            $table->string('pattern', 40)->nullable();
            $table->string('material', 80)->nullable();
            $table->string('unit', 12)->default('yard');
            $table->unsignedBigInteger('price_minor')->index();
            $table->unsignedInteger('stock_quantity')->default(0);
            $table->unsignedInteger('reserved_quantity')->default(0);
            $table->unsignedSmallInteger('min_order_quantity')->default(1);
            $table->string('image_path')->nullable();
            // draft | active | archived
            $table->string('status', 12)->default('draft')->index();
            $table->boolean('is_demo')->default(false)->index();
            $table->timestamps();

            $table->index(['status', 'category', 'price_minor']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fabrics');
    }
};
