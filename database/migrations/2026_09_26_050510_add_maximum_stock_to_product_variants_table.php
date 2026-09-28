<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->decimal('maximum_stock', 12, 3)->nullable()->after('minimum_stock');
        });

        DB::table('product_variants')->whereNull('maximum_stock')->update([
            'maximum_stock' => DB::raw('minimum_stock * 3'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table): void {
            $table->dropColumn('maximum_stock');
        });
    }
};
