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
        Schema::table('cash_sessions', function (Blueprint $table): void {
            $table->date('business_date')->nullable()->after('cash_register_id');
            $table->foreignId('closed_by')->nullable()->after('closed_at')->constrained('users')->nullOnDelete();
            $table->foreignId('verified_by')->nullable()->after('closed_by')->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable()->after('verified_by');
            $table->decimal('expected_card', 12, 2)->nullable()->after('expected_cash');
            $table->decimal('expected_transfer', 12, 2)->nullable()->after('expected_card');
            $table->decimal('expected_other', 12, 2)->nullable()->after('expected_transfer');
            $table->decimal('actual_card', 12, 2)->nullable()->after('actual_cash');
            $table->decimal('actual_transfer', 12, 2)->nullable()->after('actual_card');
            $table->decimal('actual_other', 12, 2)->nullable()->after('actual_transfer');
            $table->decimal('actual_change', 12, 2)->nullable()->after('actual_other');
            $table->text('cashier_notes')->nullable()->after('difference');
            $table->text('verification_notes')->nullable()->after('cashier_notes');
            $table->unique(['cash_register_id', 'business_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table): void {
            $table->dropUnique(['cash_register_id', 'business_date']);
            $table->dropForeign(['closed_by']);
            $table->dropForeign(['verified_by']);
            $table->dropColumn([
                'actual_card',
                'actual_change',
                'actual_other',
                'actual_transfer',
                'business_date',
                'cashier_notes',
                'closed_by',
                'expected_card',
                'expected_other',
                'expected_transfer',
                'verification_notes',
                'verified_at',
                'verified_by',
            ]);
        });
    }
};
