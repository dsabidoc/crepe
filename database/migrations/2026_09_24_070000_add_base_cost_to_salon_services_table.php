<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salon_services', function (Blueprint $table): void {
            $table->decimal('base_cost', 12, 2)->nullable()->after('base_price');
        });
    }

    public function down(): void
    {
        Schema::table('salon_services', function (Blueprint $table): void {
            $table->dropColumn('base_cost');
        });
    }
};
