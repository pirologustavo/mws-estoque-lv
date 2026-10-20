<?php

namespace App\Http\Controllers;

use App\Models\Estoque;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

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
        $estoque = Estoque::select('estoqid', 'descricao', 'tipo', 'quantidade_atual', 'quantidade_minima', 'valor_venda')->get();
        return response()->json($estoque);
    }

    public function buscarPorId(string $id)
    {
        $estoque = Estoque::select('estoqid', 'descricao', 'tipo', 'quantidade_atual', 'quantidade_minima', 'valor_venda')->where('estoqid', $id)->first();

        if (!$estoque) {
            return response()->json(['message' => "Item não encontrado!"], 404);
        }

        return response()->json($estoque);
    }

    public function atualizarEstoque(Request $request, string $id)
    {
        $estoque = estoque::where('estoqid', $id)->first();

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

        $itens = Estoque::where('descricao', 'like', "%{$busca}%")
            ->limit(10)
            ->get(['estoqid', 'descricao', 'valor_venda']);

        return response()->json($itens);
    }

    public function alterarEstoque(Request $request)
    {
        $itens = $request->all();

        return DB::transaction(function () use ($itens) {
            $processados = [];

            foreach ($itens as $item) {
                $produto = Estoque::where('estoqid', $item['id'])->first();

                if ($produto) {
                    $produto->decrement('quantidade_atual', $item['qtd']);

                    $processados[] = $produto;
                }
            }

            return response()->json([
                'message' => 'Estoque atualizado com sucesso',
                'itens' => $processados
            ]);
        });
    }

    public function estornarItens(Request $request)
    {
        $itens = $request->all();

        return DB::transaction(function () use ($itens) {
            foreach ($itens as $item) {
                $produto = Estoque::where('estoqid', $item['id'])->first();
                if ($produto) {
                    $produto->decrement('quantidade_atual', $item['qtd']);
                }
            }
            return response()->json(['message' => 'Estoque baixado com sucesso']);
        });
    }

    public function deletarEstoque(string $id)
    {
        return DB::transaction(function () use ($id) {
            $estoque = Estoque::where('estoqid', $id)->first();

            if (!$estoque) {
                return response()->json(['message' => 'Estoque não encontrado'], 404);
            }

            $vinculoOS = DB::table('os_itens')->where('estoqid', $id)->exists();

            if ($vinculoOS) {
                return response()->json([
                    'message' => 'Não é possível deletar: este item possui histórico em Ordens de Serviço.'
                ], 422);
            }

            $estoque->delete();

            return response()->json(['message' => "Estoque id $id foi deletado com sucesso!"]);
        });
    }
}
