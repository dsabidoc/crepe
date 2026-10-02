<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->decimal('listed_total', 12, 2)->default(0)->after('estimated_total');
            $table->decimal('discount_total', 12, 2)->default(0)->after('listed_total');
            $table->decimal('charged_total', 12, 2)->default(0)->after('discount_total');
        });

        DB::table('tickets')->orderBy('id')->chunkById(250, function (Collection $tickets): void {
            $ticketIds = $tickets->pluck('id');
            $listedTotals = DB::table('ticket_items')
                ->whereIn('ticket_id', $ticketIds)
                ->where('status', 'active')
                ->selectRaw('ticket_id, COALESCE(SUM(line_total), 0) as total')
                ->groupBy('ticket_id')
                ->pluck('total', 'ticket_id');
            $adjustmentTotals = DB::table('ticket_adjustments')
                ->whereIn('ticket_id', $ticketIds)
                ->selectRaw('ticket_id, COALESCE(SUM(amount), 0) as total')
                ->groupBy('ticket_id')
                ->pluck('total', 'ticket_id');

            foreach ($ticketIds as $ticketId) {
                $listedTotal = round((float) ($listedTotals[$ticketId] ?? 0), 2);
                $adjustmentTotal = round((float) ($adjustmentTotals[$ticketId] ?? 0), 2);

                DB::table('tickets')->where('id', $ticketId)->update([
                    'listed_total' => $listedTotal,
                    'discount_total' => max(0, -$adjustmentTotal),
                    'charged_total' => max(0, $listedTotal + $adjustmentTotal),
                ]);
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tickets', function (Blueprint $table) {
            $table->dropColumn(['listed_total', 'discount_total', 'charged_total']);
        });
    }
};
