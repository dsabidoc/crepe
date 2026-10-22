<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table): void {
            $table->decimal('color_bar_multiplier', 10, 4)->nullable()->after('color');
        });

        Schema::table('products', function (Blueprint $table): void {
            $table->boolean('is_reception_usable')->default(true)->after('is_color_bar_usable');
        });

        // Preserve the former exclusive behavior for the catalog that already exists.
        DB::table('products')->where('is_color_bar_usable', true)->update(['is_reception_usable' => false]);

        foreach ([
            ['Decolorante', '#E98A55', 1.9],
            ['Peroxido', '#6B9DEA', 0.3],
            ['Tinte SCH', '#9A5EEA', 3.7],
            ['Tinte Pm', '#B95070', 3.7],
            ['Keratina y Botox', '#1A9B74', 4.7],
            ['Colagenato', '#4C7EF3', 16.5],
            ['Ceramidato', '#D98A4A', 16.5],
            ['Mascarillas', '#CA6D9B', 2.0],
            ['Mascarilla Metal', '#64748B', 4.0],
        ] as [$name, $color, $multiplier]) {
            DB::table('product_categories')->updateOrInsert(
                ['name' => $name],
                ['color' => $color, 'color_bar_multiplier' => $multiplier, 'updated_at' => now(), 'created_at' => now()],
            );
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table): void {
            $table->dropColumn('is_reception_usable');
        });

        Schema::table('product_categories', function (Blueprint $table): void {
            $table->dropColumn('color_bar_multiplier');
        });
    }
};
