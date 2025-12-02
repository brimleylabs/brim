<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(config('brim.telemetry.store.table', 'brim_telemetry'), function (Blueprint $table) {
            $table->id();
            $table->string('event', 50)->index();
            $table->jsonb('data');
            $table->timestamp('occurred_at')->index();

            // Composite index for efficient querying
            $table->index(['event', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(config('brim.telemetry.store.table', 'brim_telemetry'));
    }
};
