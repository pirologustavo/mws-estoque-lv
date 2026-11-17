<?php

namespace App\Saga;

use App\Models\Estoque;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class StockAdjustmentService
{
    public function normalize(array $items): array
    {
        $normalized = collect($items)->groupBy('id')
            ->map(fn ($group, $id) => ['id' => (int) $id, 'qtd' => $group->sum(fn ($item) => (int) $item['qtd'])])
            ->filter(fn ($item) => $item['qtd'] !== 0)
            ->sortBy('id')->values()->all();

        if (! $normalized) {
            throw new StockAdjustmentRejected('O ajuste de estoque não pode ser zero.');
        }

        return $normalized;
    }

    public function apply(string $key, array $items): array
    {
        $normalized = $this->normalize($items);
        $payloadHash = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));

        try {
            return DB::transaction(fn () => $this->applyNormalized($key, $normalized, $payloadHash));
        } catch (QueryException $exception) {
            $existing = DB::table('stock_operations')->where('idempotency_key', $key)->first();
            if ($existing) {
                if (! hash_equals($existing->payload_hash, $payloadHash)) {
                    throw new StockAdjustmentRejected('Idempotency-Key reutilizado com outro payload.');
                }
                if ($existing->response_json) {
                    return json_decode($existing->response_json, true) ?? [];
                }
            }
            throw $exception;
        }
    }

    public function applyWithinTransaction(string $key, array $items): array
    {
        $normalized = $this->normalize($items);
        $payloadHash = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));

        return $this->applyNormalized($key, $normalized, $payloadHash);
    }

    private function applyNormalized(string $key, array $items, string $payloadHash): array
    {
        if ($key === '' || strlen($key) > 120) {
            throw new StockAdjustmentRejected('Idempotency-Key obrigatório.');
        }

        $existing = DB::table('stock_operations')->where('idempotency_key', $key)->first();
        if ($existing) {
            if (! hash_equals($existing->payload_hash, $payloadHash)) {
                throw new StockAdjustmentRejected('Idempotency-Key reutilizado com outro payload.');
            }
            if ($existing->response_json) {
                return json_decode($existing->response_json, true) ?? [];
            }
            throw new StockAdjustmentRejected('A operação idempotente anterior está incompleta.');
        }

        DB::table('stock_operations')->insert([
            'idempotency_key' => $key, 'payload_hash' => $payloadHash,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $stocks = [];
        foreach ($items as $item) {
            $stock = Estoque::where('estoqid', $item['id'])->lockForUpdate()->first();
            if (! $stock || (! $stock->ativo && $item['qtd'] > 0)) {
                throw new StockAdjustmentRejected('Item inexistente ou arquivado.');
            }
            if ($item['qtd'] > 0 && $stock->tipo === 'peca' && $stock->quantidade_atual < $item['qtd']) {
                throw new StockAdjustmentRejected('Saldo de estoque insuficiente.');
            }
            $stocks[$item['id']] = $stock;
        }

        foreach ($items as $item) {
            $stock = $stocks[$item['id']];
            if ($stock->tipo !== 'peca') {
                continue;
            }
            if ($item['qtd'] > 0) {
                $stock->decrement('quantidade_atual', $item['qtd']);
            } else {
                $stock->increment('quantidade_atual', abs($item['qtd']));
            }
        }

        $body = ['message' => 'Estoque ajustado com sucesso.', 'items' => $items];
        DB::table('stock_operations')->where('idempotency_key', $key)->update([
            'response_json' => json_encode($body, JSON_THROW_ON_ERROR), 'updated_at' => now(),
        ]);

        return $body;
    }
}
