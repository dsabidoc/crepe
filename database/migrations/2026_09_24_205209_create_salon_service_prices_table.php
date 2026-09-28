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
        Schema::create('salon_service_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('salon_service_id')->constrained()->cascadeOnDelete();
            $table->string('tier', 1);
            $table->decimal('cost', 12, 2)->nullable();
            $table->decimal('sale_price', 12, 2);
            $table->timestamps();

            $table->unique(['salon_service_id', 'tier']);
        });

        DB::table('salon_services')
            ->where('price_type', 'fixed')
            ->orderBy('id')
            ->eachById(function (object $service): void {
                foreach (['A', 'B', 'C'] as $tier) {
                    DB::table('salon_service_prices')->insert([
                        'salon_service_id' => $service->id,
                        'tier' => $tier,
                        'cost' => $service->base_cost,
                        'sale_price' => $service->base_price,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('salon_service_prices');
    }
};
