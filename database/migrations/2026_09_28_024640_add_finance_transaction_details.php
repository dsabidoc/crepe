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
            $table->string('direction', 10)->nullable()->after('type');
            $table->foreignId('transfer_to_account_id')->nullable()->after('finance_account_id')->constrained('finance_accounts')->nullOnDelete();
            $table->foreignId('finance_expense_category_id')->nullable()->after('concept')->constrained('finance_expense_categories')->nullOnDelete();
            $table->index(['type', 'direction']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('finance_transactions', function (Blueprint $table) {
            $table->dropIndex('finance_transactions_type_direction_index');
            $table->dropForeign(['transfer_to_account_id']);
            $table->dropForeign(['finance_expense_category_id']);
            $table->dropColumn(['direction', 'transfer_to_account_id', 'finance_expense_category_id']);
        });
    }
};
