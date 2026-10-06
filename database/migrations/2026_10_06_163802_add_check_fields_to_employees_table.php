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
        Schema::table('employees', function (Blueprint $table): void {
            $table->string('check_pin', 4)->nullable()->unique()->after('user_id');
            $table->boolean('requires_check_in')->default(true)->after('is_bookable');
        });

        DB::table('employees')->orderBy('id')->each(function (object $employee): void {
            $requiresCheckIn = mb_strtolower(trim((string) $employee->position)) !== 'dueña';
            DB::table('employees')->where('id', $employee->id)->update([
                'check_pin' => $requiresCheckIn ? str_pad((string) (1000 + $employee->id), 4, '0', STR_PAD_LEFT) : null,
                'requires_check_in' => $requiresCheckIn,
            ]);
        });

        DB::table('app_settings')->updateOrInsert(
            ['key' => 'attendance.tracking_starts_on'],
            ['value' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now()],
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table): void {
            $table->dropUnique(['check_pin']);
            $table->dropColumn(['check_pin', 'requires_check_in']);
        });

        DB::table('app_settings')->where('key', 'attendance.tracking_starts_on')->delete();
    }
};
