<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\EstoqueController;
use App\Http\Middleware\InternalServiceAuth;
use App\Http\Middleware\SharedServiceTokenAuth;
use App\Models\Estoque;

Route::get('/ping', function () {
    return response()->json(['ping' => true]);
});

Route::middleware(InternalServiceAuth::class)->prefix('internal/v1')->group(function () {
    Route::post('/stock-adjustments', [EstoqueController::class, 'ajustarPorApi']);
    Route::get('/itens-estoque', function (\Illuminate\Http\Request $request) {
        $validated = $request->validate([
            'ids' => 'required|array|min:1|max:200', 'ids.*' => 'integer|min:1',
            'include_archived' => 'sometimes|boolean',
        ]);
        $query = Estoque::whereIn('estoqid', $validated['ids']);
        if (!$request->boolean('include_archived')) $query->where('ativo', true);
        return response()->json($query->get(['estoqid', 'descricao', 'tipo', 'quantidade_atual', 'valor_venda', 'ativo']));
    });
});

Route::middleware(SharedServiceTokenAuth::class)->group(function () {
    Route::post('/v1/stock-adjustments', [EstoqueController::class, 'ajustarPorApi']);
    Route::post('/estoque/salvar', [EstoqueController::class, 'salvar']);
    Route::get('/estoque/listar', [EstoqueController::class, 'listar']);
    Route::get('/estoque/buscarPorId/{id}', [EstoqueController::class, 'buscarPorId']);
    Route::put('/estoque/atualizarEstoque/{id}', [EstoqueController::class, 'atualizarEstoque']);
    Route::get('/estoque/buscar', [EstoqueController::class, 'buscar']);
    Route::post('/estoque/alterarEstoque', [EstoqueController::class, 'alterarEstoque']);
    Route::post('/estoque/estornarItens', [EstoqueController::class, 'estornarItens']);
    Route::post('estoque/deletarEstoque/{id}', [EstoqueController::class, 'deletarEstoque']);
});

