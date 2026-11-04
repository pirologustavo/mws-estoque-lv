<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Identity and access tokens are owned by Funcionario-lv.
    }

    public function down(): void
    {
        // No local identity tables belong to this service.
    }
};
