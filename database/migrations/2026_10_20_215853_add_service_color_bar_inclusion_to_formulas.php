<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salon_services', function (Blueprint $table): void {
            $table->boolean('color_bar_included')->default(false);
        });
        Schema::table('color_formulas', function (Blueprint $table): void {
            $table->foreignId('ticket_item_id')->nullable()->constrained('ticket_items')->nullOnDelete();
            $table->string('service_name_snapshot', 150)->nullable();
            $table->boolean('color_bar_included')->default(false);
        });
        Schema::table('color_formula_items', function (Blueprint $table): void {
            $table->decimal('charged_unit_price', 12, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('color_formula_items', function (Blueprint $table): void {
            $table->dropColumn('charged_unit_price');
        });
        Schema::table('color_formulas', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('ticket_item_id');
            $table->dropColumn(['service_name_snapshot', 'color_bar_included']);
        });
        Schema::table('salon_services', function (Blueprint $table): void {
            $table->dropColumn('color_bar_included');
        });
    }
};
