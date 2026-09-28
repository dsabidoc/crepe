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
        Schema::create('finance_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120)->unique();
            $table->string('type', 30)->default('other')->index();
            $table->decimal('initial_balance', 12, 2)->default(0);
            $table->boolean('is_active')->default(true)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        $now = now();
        DB::table('finance_accounts')->insert([
            ['name' => 'Efectivo', 'type' => 'cash', 'initial_balance' => 0, 'is_active' => true, 'created_by' => null, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Cuenta bancaria', 'type' => 'bank', 'initial_balance' => 0, 'is_active' => true, 'created_by' => null, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('finance_accounts');
    }
};
