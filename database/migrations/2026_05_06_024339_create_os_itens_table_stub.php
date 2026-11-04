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
        // os_itens belongs to Os-lv and is never created in this service schema.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No owned table to remove.
    }
};
