<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('color_bar_depletion_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('created_by')->constrained('users');
            $table->uuid('request_key');
            $table->string('request_hash', 64);
            $table->string('notes', 180)->nullable();
            $table->timestamps();
            $table->unique(['created_by', 'request_key'], 'color_bar_depletion_request_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('color_bar_depletion_batches');
    }
};
