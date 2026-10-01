<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('app_settings')
            ->where('key', 'cash.opening_float')
            ->whereIn('value', ['2500', '2500.00'])
            ->update(['value' => '1250.00', 'updated_at' => now()]);
    }

    public function down(): void
    {
        DB::table('app_settings')
            ->where('key', 'cash.opening_float')
            ->where('value', '1250.00')
            ->update(['value' => '2500.00', 'updated_at' => now()]);
    }
};
