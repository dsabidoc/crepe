<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('product_brand_id')->nullable()->after('product_category_id')->constrained()->nullOnDelete();
        });

        DB::table('products')->whereNotNull('brand')->where('brand', '!=', '')->select('brand')->distinct()->orderBy('brand')->each(function (object $product): void {
            $brandId = DB::table('product_brands')->insertGetId(['name' => $product->brand, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('products')->where('brand', $product->brand)->update(['product_brand_id' => $brandId]);
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_brand_id');
        });
    }
};
