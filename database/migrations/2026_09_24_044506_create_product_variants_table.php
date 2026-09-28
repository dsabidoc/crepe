<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name')->default('Estándar');
            $table->string('sku')->nullable()->unique();
            $table->string('base_unit', 24);
            $table->decimal('content_quantity', 12, 3);
            $table->decimal('cost', 12, 4)->default(0);
            $table->decimal('sale_price', 12, 2)->default(0);
            $table->decimal('minimum_stock', 12, 3)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
