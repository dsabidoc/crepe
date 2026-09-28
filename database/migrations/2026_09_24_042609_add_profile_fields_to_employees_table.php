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
        Schema::table('employees', function (Blueprint $table) {
            $table->date('hired_at')->nullable()->after('position');
            $table->decimal('salary', 12, 2)->nullable()->after('hired_at');
            $table->string('salary_type')->nullable()->after('salary');
            $table->text('notes')->nullable()->after('commission_rate');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['hired_at', 'salary', 'salary_type', 'notes']);
        });
    }
};
