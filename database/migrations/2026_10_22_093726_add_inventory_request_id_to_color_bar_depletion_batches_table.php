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
        Schema::table('color_bar_depletion_batches', function (Blueprint $table) {
            $table->foreignId('inventory_request_id')->nullable()->constrained('inventory_requests');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('color_bar_depletion_batches', function (Blueprint $table) {
            $table->dropConstrainedForeignId('inventory_request_id');
        });
    }
};
