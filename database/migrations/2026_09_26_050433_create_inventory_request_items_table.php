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
        Schema::create('inventory_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->decimal('requested_quantity', 14, 3);
            $table->decimal('delivered_quantity', 14, 3)->default(0);
            $table->string('unit', 24);
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['inventory_request_id', 'product_variant_id'], 'inventory_request_item_variant_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_request_items');
    }
};
