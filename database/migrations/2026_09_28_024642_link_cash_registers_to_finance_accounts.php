<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('cash_registers', function (Blueprint $table) {
            $table->foreignId('finance_account_id')->nullable()->after('is_active')->constrained('finance_accounts')->nullOnDelete();
        });

        foreach ([['old' => 'Efectivo', 'new' => 'C-Efectivo'], ['old' => 'Cuenta bancaria', 'new' => 'C-Bancomer']] as $rename) {
            $oldId = DB::table('finance_accounts')->where('name', $rename['old'])->value('id');
            $newExists = DB::table('finance_accounts')->where('name', $rename['new'])->exists();
            if ($oldId && ! $newExists) {
                DB::table('finance_accounts')->where('id', $oldId)->update(['name' => $rename['new']]);
            } elseif ($oldId && $newExists) {
                DB::table('finance_accounts')->where('id', $oldId)->delete();
            }
        }

        $now = now();
        foreach ([['name' => 'C-Efectivo', 'type' => 'cash', 'primary' => true], ['name' => 'C-Bancomer', 'type' => 'bank', 'primary' => true], ['name' => 'C-Lou', 'type' => 'cash', 'primary' => false], ['name' => 'C-Pilar', 'type' => 'cash', 'primary' => false], ['name' => 'C-Recepción 1', 'type' => 'cash', 'primary' => false], ['name' => 'C-Recepción 2', 'type' => 'cash', 'primary' => false]] as $account) {
            $id = DB::table('finance_accounts')->where('name', $account['name'])->value('id') ?? DB::table('finance_accounts')->insertGetId([
                'name' => $account['name'], 'type' => $account['type'], 'initial_balance' => 0, 'is_active' => true,
                'is_primary' => $account['primary'], 'created_by' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('finance_accounts')->where('id', $id)->update(['type' => $account['type'], 'is_primary' => $account['primary'], 'is_active' => true]);
            if ($account['name'] === 'C-Recepción 1') {
                DB::table('cash_registers')->where('code', 'REC-01')->update(['finance_account_id' => $id]);
            }
            if ($account['name'] === 'C-Recepción 2') {
                DB::table('cash_registers')->where('code', 'REC-02')->update(['finance_account_id' => $id]);
            }
        }

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cash_registers', function (Blueprint $table) {
            $table->dropForeign(['finance_account_id']);
            $table->dropColumn('finance_account_id');
        });
    }
};
