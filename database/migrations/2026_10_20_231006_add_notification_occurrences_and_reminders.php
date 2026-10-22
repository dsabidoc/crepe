<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropUnique('notifications_recipient_event_unique');
            $table->unsignedInteger('occurrence')->default(1);
            $table->timestamp('reminded_at')->nullable();
            $table->unique(['notifiable_type', 'notifiable_id', 'type', 'subject_id', 'occurrence'], 'notifications_recipient_occurrence_unique');
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Las recurrencias preservan historial. Esta migración requiere una corrección hacia adelante.');
    }
};
