<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Estoque extends Model
{
    protected $table = 'estoque';
    protected $primaryKey = 'estoqid';
    protected $fillable = ['descricao', 'tipo', 'quantidade_atual', 'quantidade_minima', 'valor_custo', 'valor_venda'];
}
