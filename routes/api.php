<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EstoqueController;

Route::get('/ping', function () {
    return response()->json(['ping' => true]);
});

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/estoque/salvar', [EstoqueController::class, 'salvar']);
    Route::get('/estoque/listar', [EstoqueController::class, 'listar']);
    Route::get('/estoque/buscarPorId/{id}', [EstoqueController::class, 'buscarPorId']);
    Route::put('/estoque/atualizarEstoque/{id}', [EstoqueController::class, 'atualizarEstoque']);
    Route::get('/estoque/buscar', [EstoqueController::class, 'buscar']);
    Route::post('/estoque/alterarEstoque', [EstoqueController::class, 'alterarEstoque']);
    Route::post('/estoque/estornarItens', [EstoqueController::class, 'estornarItens']);
    Route::post('estoque/deletarEstoque/{id}', [EstoqueController::class, 'deletarEstoque']);
});

