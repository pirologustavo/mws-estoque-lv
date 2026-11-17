# Contrato HTTP

Importe `api.postman_collection.json` no Postman. Configure base_url e token emitido pelo serviço Funcionário; endpoints internos exigem internal_token. IDs e exemplos precisam corresponder a registros existentes. Webhooks exigem assinatura HMAC produzida pelo Mercado Pago; não envie status de pagamento pelo navegador.

| Método | Rota | Acesso |
|---|---|---|
| POST | `/api/estoque/alterarEstoque` | Bearer |
| PUT | `/api/estoque/atualizarEstoque/{id}` | Bearer |
| GET | `/api/estoque/buscar` | Bearer |
| GET | `/api/estoque/buscarPorId/{id}` | Bearer |
| POST | `/api/estoque/deletarEstoque/{id}` | Bearer |
| POST | `/api/estoque/estornarItens` | Bearer |
| GET | `/api/estoque/listar` | Bearer |
| POST | `/api/estoque/salvar` | Bearer |
| GET | `/api/internal/v1/itens-estoque` | interno |
| POST | `/api/internal/v1/stock-adjustments` | interno |
| GET | `/api/ping` | público |
| POST | `/api/v1/stock-adjustments` | Bearer |
