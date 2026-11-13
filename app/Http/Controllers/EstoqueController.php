<?php

namespace App\Http\Controllers;

use App\Models\Estoque;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use App\Saga\StockAdjustmentRejected;
use App\Saga\StockAdjustmentService;

class EstoqueController extends Controller
{
    public function salvar(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'descricao'         => 'required|string|max:255',
            'tipo'              => 'required|in:peca,servico',
            'quantidade_atual'  => 'required_if:tipo,peca|numeric|min:0',
            'quantidade_minima' => 'required_if:tipo,peca|integer|min:0',
            'valor_custo'       => 'nullable|numeric|min:0',
            'valor_venda'       => 'required|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $estoque = Estoque::create($request->all());

        return response()->json([
            'message' => 'Item cadastrado com sucesso!',
            'data'    => $estoque
        ], 201);
    }

    public function listar()
    {
        $estoque = Estoque::where('ativo', true)->select('estoqid', 'descricao', 'tipo', 'quantidade_atual', 'quantidade_minima', 'valor_venda')->get();
        return response()->json($estoque);
    }

    public function buscarPorId(string $id)
    {
        $estoque = Estoque::where('ativo', true)->select('estoqid', 'descricao', 'tipo', 'quantidade_atual', 'quantidade_minima', 'valor_venda')->where('estoqid', $id)->first();

        if (!$estoque) {
            return response()->json(['message' => "Item não encontrado!"], 404);
        }

        return response()->json($estoque);
    }

    public function atualizarEstoque(Request $request, string $id)
    {
        $estoque = Estoque::where('ativo', true)->where('estoqid', $id)->first();

        if (!$estoque) {
            return response()->json(['message' => "estoque não encontrado"], 404);
        }

        $dados = $request->all();
        $estoque->update($dados);

        return response()->json([
            'message' => 'estoque atualizado com sucesso!',
            'data'    => $estoque
        ]);
    }

    public function buscar(Request $request)
    {
        $busca = $request->query('q');

        $itens = Estoque::where('ativo', true)->where('descricao', 'like', "%{$busca}%")
            ->limit(10)
            ->get(['estoqid', 'descricao', 'valor_venda']);

        return response()->json($itens);
    }

    public function alterarEstoque(Request $request)
    {
        $request->merge(['items' => $request->all()]);
        if (!$request->header('Idempotency-Key')) {
            $request->headers->set('Idempotency-Key', (string) \Illuminate\Support\Str::uuid());
        }
        return $this->ajustarPorApi($request);
    }

    public function ajustarPorApi(Request $request, StockAdjustmentService $adjustments)
    {
        $validated = $request->validate([
            'items' => 'required|array|min:1|max:200',
            'items.*.id' => 'required|integer|min:1',
            'items.*.qtd' => 'required|integer|not_in:0',
        ]);
        $key = (string) $request->header('Idempotency-Key');
        if ($key === '' || strlen($key) > 120) {
            return response()->json(['message' => 'Idempotency-Key obrigatório.'], 400);
        }

        try {
            $body = $adjustments->apply($key, $validated['items']);
            return response()->json($body);
        } catch (StockAdjustmentRejected $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }
    }

    public function estornarItens(Request $request)
    {
        $itens = collect($request->all())->map(fn ($item) => [
            'id' => (int) $item['id'], 'qtd' => -abs((int) $item['qtd']),
        ])->all();
        $request->merge(['items' => $itens]);
        if (!$request->header('Idempotency-Key')) {
            $request->headers->set('Idempotency-Key', (string) \Illuminate\Support\Str::uuid());
        }
        return $this->ajustarPorApi($request);
    }

    public function deletarEstoque(string $id)
    {
        return DB::transaction(function () use ($id) {
            $estoque = Estoque::where('estoqid', $id)->first();

            if (!$estoque) {
                return response()->json(['message' => 'Estoque não encontrado'], 404);
            }

            $estoque->update(['ativo' => false]);

            return response()->json(['message' => "Estoque id $id foi deletado com sucesso!"]);
        });
    }
}
