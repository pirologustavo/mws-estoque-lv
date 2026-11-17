# Contrato HTTP — Estoque-lv

## Apresentação no Postman

Importe [a collection desta API](api.postman_collection.json). Ela contém exemplos de sucesso/erro, descrições, scripts de validação e captura de IDs. Os exemplos são ilustrativos e não são respostas coletadas do ambiente.

Para apresentar as cinco APIs em sequência, importe também `mws-apresentacao.postman_environment.json` do pacote de apresentação do repositório de integração e selecione **MWS — Apresentação**. A URL deste serviço é `{{estoque_base_url}}`; ajuste-a para Docker Compose, port-forward ou endpoint real. As cinco URLs são independentes.

Execute **Login — capturar token compartilhado**, com `usr` e `password` de um usuário existente da API Funcionário. O token **Sanctum** é salvo como `token` no ambiente. Os segredos são distribuídos em branco. Sem ambiente selecionado, capturas ficam restritas à própria collection.

A API Funcionário deve estar acessível para validar o Bearer das demais APIs. Endpoints internos exigem `internal_token`, enviado em `X-Internal-Token`. IDs de referência são capturados nas collections de Cliente, Veículo, Funcionário e Estoque antes de abrir uma OS.

Ajustes públicos/internos usam `items` e `Idempotency-Key`. As rotas de compatibilidade alterarEstoque/estornarItens recebem array na raiz. Positivo baixa estoque; negativo devolve. O fluxo da OS utiliza RabbitMQ, sem necessidade de ajustes HTTP manuais.

## Rotas de negócio

Os nomes dos parâmetros abaixo indicam as variáveis da apresentação; o servidor recebe seus valores numéricos nos caminhos. Healthcheck público em `/api/ping`; o healthcheck Laravel `/up` é operacional e não faz parte deste contrato de negócio.

| Método | Rota | Acesso |
|---|---|---|
| GET | `/api/ping` | Público |
| POST | `/api/estoque/salvar` | Bearer |
| GET | `/api/estoque/listar` | Bearer |
| GET | `/api/estoque/buscar` | Bearer |
| GET | `/api/estoque/buscarPorId/{estoque_id}` | Bearer |
| PUT | `/api/estoque/atualizarEstoque/{estoque_id}` | Bearer |
| POST | `/api/v1/stock-adjustments` | Bearer + Idempotency-Key |
| POST | `/api/estoque/alterarEstoque` | Bearer + Idempotency-Key |
| POST | `/api/estoque/estornarItens` | Bearer + Idempotency-Key |
| GET | `/api/internal/v1/itens-estoque` | X-Internal-Token |
| POST | `/api/internal/v1/stock-adjustments` | X-Internal-Token + Idempotency-Key |
| POST | `/api/estoque/deletarEstoque/{estoque_id}` | Bearer |
