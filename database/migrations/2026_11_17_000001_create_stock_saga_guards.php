<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_saga_guards', function (Blueprint $table): void {
            $table->string('request_event_id', 120)->primary();
            $table->string('payload_hash', 64);
            $table->string('state', 32);
            $table->json('items');
            $table->uuid('result_event_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_saga_guards');
    }
};
