# Estoque-lv — arquitetura e validação

Catálogo, saldo e reserva/devolução idempotente de estoque via RabbitMQ.

Serviço Laravel/PHP 8.4 independente, com Dockerfile, pipeline CI/CD e manifestos em `deploy/k8s`. Persistência SQL exclusiva: `estoque_db`, com usuário próprio. Nenhum acesso direto ao banco de outro serviço: consultas remotas usam REST autenticado e correlação X-Correlation-ID. Cliente também mantém auditoria em MongoDB, com credenciais próprias.

OS e Estoque usam Saga coreografada: eventos de solicitação e resultado no RabbitMQ, outbox transacional, inbox idempotente, retentativas e dead-letter. Falhas de estoque restauram o snapshot da OS ou cancelam a abertura. A coreografia mantém cada serviço responsável por sua transação local. Não há API Execucao-lv; execução pertence à OS. Pagamentos são confirmados consultando Mercado Pago; webhook assinado e reconciliação periódica recuperam notificações perdidas. Cancelamento concluído provoca estorno idempotente.

## Executar e verificar

```bash
composer install
php artisan test --coverage-clover=coverage.xml
python3 scripts/check_coverage.py coverage.xml --minimum 80
php vendor/bin/pint --test app tests
phpstan analyse app --level=1 --memory-limit=512M --no-progress --autoload-file=vendor/autoload.php
docker build -t estoque-lv:local .
```

Use PHP 8.4 com sockets, pdo_sqlite e PCOV nos testes; Cliente requer mongodb em produção. Os testes usam SQLite em memória e integrações simuladas. Para BDD da OS: `php vendor/bin/phpunit --group=bdd`. CI publica o artifact `coverage` a cada execução. A cobertura mínima é aplicada ao serviço inteiro, sem exclusões de código de aplicação.

## Entrega

CI executa testes, gate de cobertura, Pint, PHPStan nível 1 e build. CD depende da conclusão bem-sucedida do CI na main; configurações AWS/ECR/EKS e secrets são externas. Proteção de branch é configurada no GitHub pelo responsável. Consulte `.github/workflows` para os nomes exatos dos secrets e `deploy/k8s` para os recursos. Não confundir build local aprovado com deploy remoto executado.

[Rotas e autenticação](API.md) · [Collection Postman](api.postman_collection.json)
