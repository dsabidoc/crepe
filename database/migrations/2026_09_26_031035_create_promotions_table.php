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
        Schema::create('promotions', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable()->unique();
            $table->string('method')->default('automatic');
            $table->string('type');
            $table->string('value_type')->nullable();
            $table->decimal('value', 12, 2)->nullable();
            $table->decimal('minimum_amount', 12, 2)->nullable();
            $table->unsignedInteger('buy_quantity')->nullable();
            $table->unsignedInteger('reward_quantity')->nullable();
            $table->json('target')->nullable();
            $table->json('reward')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->boolean('one_per_customer')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();
            $table->index(['status', 'starts_at', 'ends_at']);
            $table->index(['type', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promotions');
    }
};
