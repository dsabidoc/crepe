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
        Schema::table('appointments', function (Blueprint $table): void {
            $table->foreignId('secondary_employee_id')->nullable()->after('primary_employee_id')->constrained('employees')->nullOnDelete();
            $table->index(['secondary_employee_id', 'starts_at', 'ends_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table): void {
            $table->dropForeign(['secondary_employee_id']);
            $table->dropIndex(['secondary_employee_id', 'starts_at', 'ends_at']);
            $table->dropColumn('secondary_employee_id');
        });
    }
};
