# Sistema de Gestão de Oficina Mecânica — API de Estoque

### MVP - Tech Challenge

Catálogo de peças e serviços, reserva e devolução de saldo idempotentes.

### Arquitetura e Tecnologias

- **Linguagem:** PHP 8.4 e Laravel.
- **Banco de Dados:** MySQL 8.0, database exclusivo `estoque_db`; SQLite em memória nos testes.
- **Orquestração:** Docker e Kubernetes.
- **Comunicação:** REST autenticado; RabbitMQ na integração OS/Estoque.
- **Observabilidade:** Datadog APM, logs JSON, correlação e métricas DogStatsD.

### Ecossistema de Microsserviços e Front-end

A API integra o ecossistema Cliente, Veículo, Estoque, Funcionário e OS, consumido pelo Monólito PHP. Cada API possui repositório, database, usuário SQL e pipeline próprios. Referências remotas usam HTTP; nenhum serviço consulta diretamente o banco de outro.

## Saga das ordens de serviço

A Saga é coreografada entre OS e Estoque via RabbitMQ. A coreografia mantém cada serviço responsável pela própria transação e permite recuperação após indisponibilidade, sem coordenador central. Outbox transacional, inbox e chaves idempotentes evitam perda de intenção e repetição de efeitos.

Rejeições de estoque cancelam a abertura ou restauram o snapshot anterior. Após cinco minutos sem resultado, a OS solicita cancelamento remoto; o Estoque desfaz o ajuste confirmado ou impede a aplicação tardia. A OS só compensa localmente depois dessa confirmação. Reenvios recuperam resultados que chegaram à DLQ. Enquanto o estoque estiver indisponível, a operação permanece bloqueada e é retomada automaticamente quando a comunicação volta.

Falha definitiva no envio do orçamento solicita cancelamento da OS e devolução das peças. Pagamentos e estornos são reconciliados pela OS. Eventos incluem `saga_id`, `event_id`, `operation_id` e `correlation_id`.

## Como executar o projeto

### Opção 1: Ambiente Local (Docker)

Para o ecossistema completo, use o [repositório de integração](https://github.com/pirologustavo/MWS-TechChallenge) e execute `docker compose up -d --build` e `./setup.sh` na raiz.

Para construir esta API separadamente:

```bash
cp .env.example .env
# Configure banco, APP_KEY, INTERNAL_API_TOKEN e URLs dos serviços no .env.
docker build -t mws-estoque-lv:local .
docker run --rm --env-file .env -p 8003:80 mws-estoque-lv:local
```

Execute `php artisan migrate --force` no container após provisionar o database e o usuário exclusivos. Nunca use `migrate:fresh` em um ambiente com dados.

### Opção 2: Ambiente Clusterizado (Kubernetes)

Provisione os bancos, ConfigMap `mws-config` e Secrets individuais com o bootstrap do repositório de integração. Aplique `deploy/k8s/` com a imagem construída para o serviço. O CD realiza essa aplicação e aguarda os rollouts. As dependências de infraestrutura precisam existir antes do deploy da aplicação.

No EKS do laboratório, o RabbitMQ usa um volume persistente local vinculado ao nó. A infraestrutura é aplicada no repositório de integração com `python3 scripts/configure-local-storage.py --context mws-eks`. Consulte o [guia de armazenamento local](https://github.com/pirologustavo/MWS-TechChallenge/tree/main/k8s/overlays/eks-local). Os dados são preservados ao recriar o pod no mesmo nó; perda ou substituição do nó exige restauração de backup.

## Testes Automatizados

```bash
composer install
php artisan test --coverage-clover=coverage.xml
python3 scripts/check_coverage.py coverage.xml --minimum 80
php vendor/bin/pint --test app tests
phpstan analyse app --level=1 --memory-limit=512M --no-progress --autoload-file=vendor/autoload.php
```

Use PHP 8.4, PDO SQLite e PCOV. A cobertura inclui todo o diretório `app`; o pipeline falha abaixo de 80% por serviço. Pint e PHPStan verificam estilo e qualidade estática.

## Resultado dos testes
<img width="1482" height="779" alt="image" src="https://github.com/user-attachments/assets/b5abd69b-71be-456d-8363-82b52fa24edd" />

## Documentação e APIs

- [Arquitetura do serviço](docs/ARCHITECTURE.md).
- [Rotas e autenticação](docs/API.md).
- [Collection Postman](docs/api.postman_collection.json).
- [Preparação da apresentação e variáveis](docs/API.md#apresentação-no-postman).

O login ocorre na API Funcionário em `POST /api/login`, com `usr` e `password`. Envie o token retornado em `Authorization: Bearer`. Chamadas internas exigem `X-Internal-Token`; use o mesmo segredo forte entre os serviços.

## Observabilidade

Healthcheck em `/up`, logs estruturados em `stderr` e propagação de `X-Correlation-ID`. Configure `DD_SERVICE`, `DD_ENV` e `DD_AGENT_HOST`. O Agent e os recursos de dashboard e alertas são provisionados pelo repositório de integração.

## CI/CD

O workflow `ci.yml` executa testes, cobertura mínima, Pint, PHPStan e build. `cd.yml` é disparado após CI aprovado de um push na `main`, publica a imagem identificada pelo SHA no ECR, aplica os manifests e aguarda o rollout no EKS.

Configure `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_REGION` e, para credenciais temporárias, `AWS_SESSION_TOKEN`. A branch `main` deve exigir PR e o check `quality-test-build`; não há bypass automático do pipeline.

[Execuções do pipeline](https://github.com/pirologustavo/mws-estoque-lv/actions).

## Autor

- Gustavo Pirolo — Cientista da Computação & Trainee Software Development.
- RM371637.
