<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('products', 'is_color_bar_supply')) {
            Schema::table('products', function (Blueprint $table) {
                $table->boolean('is_color_bar_supply')->nullable();
            });
        }

        DB::table('products')->whereNull('is_color_bar_supply')
            ->update(['is_color_bar_supply' => DB::raw('is_color_bar_usable')]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('is_color_bar_supply');
        });
    }
};
