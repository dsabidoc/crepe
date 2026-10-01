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
        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->foreignId('cash_session_id')->nullable()->after('finance_account_id')->constrained('cash_sessions')->nullOnDelete();
            $table->string('evidence_path')->nullable()->after('notes');
            $table->index(['cash_session_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->dropIndex('finance_transactions_cash_session_id_type_index');
            $table->dropForeign(['cash_session_id']);
            $table->dropColumn(['cash_session_id', 'evidence_path']);
        });
    }
};
