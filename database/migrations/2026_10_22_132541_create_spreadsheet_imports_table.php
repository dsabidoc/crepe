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
        Schema::create('spreadsheet_imports', function (Blueprint $table) {
            $table->id();
            $table->uuid('token')->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('module', 24);
            $table->string('filename');
            $table->string('format', 8);
            $table->string('mode', 24);
            $table->string('location_code', 8)->nullable();
            $table->string('status', 24);
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('change_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('preview');
            $table->text('reason')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->index(['module', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spreadsheet_imports');
    }
};
