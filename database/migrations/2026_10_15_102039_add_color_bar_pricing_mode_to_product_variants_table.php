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
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->string('color_bar_pricing_type', 16)->default('fixed')->after('sale_price');
            $table->decimal('color_bar_sale_price', 12, 2)->default(0)->after('color_bar_pricing_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn(['color_bar_pricing_type', 'color_bar_sale_price']);
        });
    }
};
