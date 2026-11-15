<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/** @mixin Builder */
class Funcionario extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'funcionarios';

    protected $primaryKey = 'funcid';

    protected $fillable = [
        'nome',
        'cargo',
        'usr',
        'password',
    ];
}
