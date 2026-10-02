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
        Schema::table('salon_services', function (Blueprint $table) {
            $table->string('commission_type', 16)->default('percentage')->after('commission_rate');
            $table->decimal('commission_fixed_amount', 12, 2)->nullable()->after('commission_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('salon_services', function (Blueprint $table) {
            $table->dropColumn(['commission_type', 'commission_fixed_amount']);
        });
    }
};
