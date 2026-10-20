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
        Schema::create('estoque', function (Blueprint $table) {
            $table->id('estoqid');
            $table->string('descricao');
            $table->enum('tipo', ['peca', 'servico']);
            $table->integer('quantidade_atual')->default(0)->nullable();
            $table->integer('quantidade_minima')->default(0)->nullable();
            $table->decimal('valor_custo', 10, 2)->default(0);
            $table->decimal('valor_venda', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estoques');
    }
};
