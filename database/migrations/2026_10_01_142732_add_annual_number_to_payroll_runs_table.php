<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->unsignedSmallInteger('payroll_year')->nullable()->after('id');
            $table->unsignedInteger('payroll_number')->nullable()->after('payroll_year');
        });

        $counters = [];
        DB::table('payroll_runs')->orderBy('period_ends_on')->orderBy('id')->get(['id', 'period_ends_on'])->each(function (object $run) use (&$counters): void {
            $year = Carbon::parse($run->period_ends_on)->year;
            $counters[$year] = ($counters[$year] ?? 0) + 1;
            DB::table('payroll_runs')->where('id', $run->id)->update(['payroll_year' => $year, 'payroll_number' => $counters[$year]]);
        });

        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->unique(['payroll_year', 'payroll_number']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payroll_runs', function (Blueprint $table): void {
            $table->dropUnique(['payroll_year', 'payroll_number']);
            $table->dropColumn(['payroll_year', 'payroll_number']);
        });
    }
};
