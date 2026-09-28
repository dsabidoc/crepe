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
        Schema::table('color_formulas', function (Blueprint $table) {
            $table->foreignId('supersedes_color_formula_id')
                ->nullable()
                ->after('inventory_location_id')
                ->constrained('color_formulas')
                ->nullOnDelete();
            $table->foreignId('corrected_by')->nullable()->after('created_by')->constrained('users')->nullOnDelete();
            $table->timestamp('corrected_at')->nullable()->after('corrected_by');
        });

        Schema::table('color_formula_items', function (Blueprint $table) {
            $table->string('notes')->nullable()->after('unit');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('color_formula_items', function (Blueprint $table) {
            $table->dropColumn('notes');
        });

        Schema::table('color_formulas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('supersedes_color_formula_id');
            $table->dropConstrainedForeignId('corrected_by');
            $table->dropColumn('corrected_at');
        });
    }
};
