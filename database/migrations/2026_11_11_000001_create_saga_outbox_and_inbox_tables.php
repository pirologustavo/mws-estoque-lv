<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_outbox', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('event_type', 120);
            $table->string('routing_key', 160);
            $table->string('aggregate_type', 80);
            $table->string('aggregate_id', 80);
            $table->json('payload');
            $table->string('correlation_id', 80)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at')->useCurrent();
            $table->timestamp('published_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->index(['published_at', 'available_at']);
        });

        Schema::create('integration_inbox', function (Blueprint $table): void {
            $table->uuid('event_id')->primary();
            $table->string('event_type', 120);
            $table->timestamp('processed_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_inbox');
        Schema::dropIfExists('integration_outbox');
    }
};
