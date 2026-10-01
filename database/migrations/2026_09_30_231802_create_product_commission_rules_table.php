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
        Schema::create('product_commission_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('minimum_sales')->default(0);
            $table->unsignedInteger('maximum_sales')->nullable();
            $table->decimal('commission_rate', 5, 2);
            $table->json('positions')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_commission_rules');
    }
};
