<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_balances', function (Blueprint $table): void {
            $table->decimal('minimum_stock', 12, 3)->nullable();
            $table->decimal('maximum_stock', 12, 3)->nullable();
            $table->boolean('limits_configured')->default(false);
        });
        Schema::table('products', function (Blueprint $table): void {
            $table->string('product_family', 100)->nullable();
            $table->foreignId('color_bar_rate_category_id')->nullable()->constrained('product_categories')->nullOnDelete();
        });
        Schema::table('inventory_requests', function (Blueprint $table): void {
            $table->string('reversal_hold_reason')->nullable();
        });
        Schema::create('inventory_adjustment_batches', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_key')->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('payload_hash', 64);
            $table->text('reason');
            $table->json('changes');
            $table->timestamps();
        });
        Schema::create('inventory_request_reversals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inventory_request_id')->unique()->constrained()->restrictOnDelete();
            $table->uuid('request_key')->unique();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->json('movements');
            $table->timestamps();
        });
        Schema::create('finance_transaction_corrections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('finance_transaction_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('replacement_transaction_id')->nullable()->constrained('finance_transactions', indexName: 'finance_correction_replacement_fk')->restrictOnDelete();
            $table->uuid('request_key')->index();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->text('reason');
            $table->json('snapshot');
            $table->timestamps();
        });
        Schema::table('finance_transactions', function (Blueprint $table): void {
            $table->uuid('transfer_group')->nullable()->index();
        });
        Schema::table('payable_payments', function (Blueprint $table): void {
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
        });
        $restrictedUsers = DB::table('users')->whereRaw('LOWER(name) = ?', ['lupis'])->pluck('id')->all();
        if ($restrictedUsers !== []) {
            DB::table('app_settings')->updateOrInsert(['key' => 'products.reception_price_only_user_ids'], ['value' => json_encode($restrictedUsers, JSON_THROW_ON_ERROR), 'updated_at' => now(), 'created_at' => now()]);
        }
        DB::table('inventory_requests')->where('code', 'SOL-000020')->update(['reversal_hold_reason' => 'Pendiente de confirmar si se realizó la entrega física.']);
        DB::table('products')->whereNull('color_bar_rate_category_id')->update(['color_bar_rate_category_id' => DB::raw('product_category_id')]);
        $locations = DB::table('inventory_locations')->whereIn('code', ['ALM', 'REC', 'CB'])->pluck('id');
        DB::table('product_variants')->orderBy('id')->chunkById(200, function ($variants) use ($locations): void {
            $rows = [];
            foreach ($variants as $variant) {
                foreach ($locations as $locationId) {
                    $rows[] = ['inventory_location_id' => $locationId, 'product_variant_id' => $variant->id, 'available_quantity' => 0, 'created_at' => now(), 'updated_at' => now()];
                }
            }
            DB::table('inventory_balances')->insertOrIgnore($rows);
            foreach ($variants as $variant) {
                DB::table('inventory_balances')->where('product_variant_id', $variant->id)->where('limits_configured', false)->update(['minimum_stock' => $variant->minimum_stock, 'maximum_stock' => $variant->maximum_stock, 'limits_configured' => true]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('payable_payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn('voided_at');
        });
        Schema::table('finance_transactions', fn (Blueprint $table) => $table->dropColumn('transfer_group'));
        Schema::dropIfExists('finance_transaction_corrections');
        Schema::dropIfExists('inventory_request_reversals');
        Schema::dropIfExists('inventory_adjustment_batches');
        Schema::table('inventory_requests', fn (Blueprint $table) => $table->dropColumn('reversal_hold_reason'));
        Schema::table('products', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('color_bar_rate_category_id');
            $table->dropColumn('product_family');
        });
        Schema::table('inventory_balances', fn (Blueprint $table) => $table->dropColumn(['minimum_stock', 'maximum_stock', 'limits_configured']));
    }
};
