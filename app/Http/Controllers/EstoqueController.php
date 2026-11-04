<?php

namespace App\Http\Controllers;

use App\Models\Estoque;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

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

    public function ajustarPorApi(Request $request)
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

        $items = collect($validated['items'])
            ->groupBy('id')
            ->map(fn ($group, $id) => ['id' => (int) $id, 'qtd' => $group->sum('qtd')])
            ->filter(fn ($item) => $item['qtd'] !== 0)
            ->sortBy('id')->values()->all();
        if (!$items) return response()->json(['message' => 'O ajuste líquido não pode ser zero.'], 422);
        $payloadHash = hash('sha256', json_encode($items, JSON_THROW_ON_ERROR));
        $existing = DB::table('stock_operations')->where('idempotency_key', $key)->first();
        if ($existing) {
            if (!hash_equals($existing->payload_hash, $payloadHash)) {
                return response()->json(['message' => 'Idempotency-Key reutilizado com outro payload.'], 409);
            }
            return response()->json(json_decode($existing->response_json, true) ?? ['message' => 'Ajuste já aplicado.']);
        }

        try {
            $body = DB::transaction(function () use ($key, $payloadHash, $items) {
                DB::table('stock_operations')->insert([
                    'idempotency_key' => $key, 'payload_hash' => $payloadHash,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                foreach ($items as $item) {
                    $stock = Estoque::where('estoqid', $item['id'])->lockForUpdate()->first();
                    if (!$stock || (!$stock->ativo && $item['qtd'] > 0)) abort(409, 'Item de estoque inexistente ou arquivado.');
                    if ($item['qtd'] > 0 && $stock->tipo === 'peca' && $stock->quantidade_atual < $item['qtd']) {
                        abort(409, 'Saldo de estoque insuficiente.');
                    }
                }
                foreach ($items as $item) {
                    $stock = Estoque::where('estoqid', $item['id'])->lockForUpdate()->first();
                    if ($stock->tipo !== 'peca') continue;
                    if ($item['qtd'] > 0) $stock->decrement('quantidade_atual', $item['qtd']);
                    else $stock->increment('quantidade_atual', abs($item['qtd']));
                }
                $body = ['message' => 'Estoque ajustado com sucesso.', 'items' => $items];
                DB::table('stock_operations')->where('idempotency_key', $key)->update([
                    'response_json' => json_encode($body, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                ]);
                return $body;
            });
            return response()->json($body);
        } catch (QueryException $exception) {
            $existing = DB::table('stock_operations')->where('idempotency_key', $key)->first();
            if ($existing) {
                if (!hash_equals($existing->payload_hash, $payloadHash)) {
                    return response()->json(['message' => 'Idempotency-Key reutilizado com outro payload.'], 409);
                }
                if ($existing->response_json) return response()->json(json_decode($existing->response_json, true));
            }
            throw $exception;
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
