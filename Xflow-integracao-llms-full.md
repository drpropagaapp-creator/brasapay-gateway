# Payment Hub — API para integração

> Payment Hub é um gateway de pagamentos Pix para o Brasil. Este documento
> descreve a API pública em formato legível por modelos de linguagem
> (padrão llms.txt), para que assistentes de IA ajudem a integrar.
>
> Base da API: https://app.xflowpayments.com. As chaves de API são geradas no painel
> em Desenvolvedores > Chaves de API.

## Visão geral

- Base URL: https://app.xflowpayments.com/api/v1
- Formato: JSON (UTF-8). Valores monetários em CENTAVOS (inteiro). Ex.: R$ 19,90 = 1990.
- Datas: ISO 8601 (UTC).
- Métodos suportados hoje: Pix (cash-in). Cartão e checkout hospedado vêm a seguir.
- Toda resposta traz um `request_id` (`req_…`) no corpo JSON e no header
  `x-request-id`. Guarde-o nos seus logs: ele identifica a requisição na tela
  "Logs da API" do painel (Integrações > Logs da API, retenção 30 dias) e no
  suporte. Requisições autenticadas ficam registradas lá com status, duração e
  código de erro — útil para depurar a integração sem abrir chamado.

## Autenticação

HTTP Basic auth: usuário = chave pública (`pk_live_…`), senha = segredo
(`sk_live_…`). Gere no painel; a pública fica sempre visível e o segredo é
mostrado uma única vez.

```
Authorization: Basic base64(pk_live_...:sk_live_...)
# cURL: curl ... -u "pk_live_...:sk_live_..."
```

- A credencial identifica a SUA empresa — cada cobrança é criada para a sua conta.
- Nunca exponha o segredo no navegador nem em repositórios versionados.
- Revogue credenciais comprometidas no painel (revogação é imediata).
- Legado: `Authorization: Bearer sk_live_...` (só o segredo) ainda funciona
  (aceita apenas segredos nossos: `sk_live_...`/`sk_test_...`).
- Allowlist de IP (opcional, painel > Chaves de API): se configurada, requisições
  de IPs fora da lista recebem `401` — mesmo com a credencial correta.

## Modo de teste (sandbox)

Crie uma chave de TESTE no painel (Chaves de API > Criar chave > ambiente
"Teste") — credenciais `pk_test_…`/`sk_test_…`. Com elas você integra sem
mover dinheiro real:

- Cobranças de teste ficam num ambiente separado — nunca aparecem no saldo,
  dashboards ou extrato. Nenhum provedor Pix é chamado; o `pix.copyPaste`
  retornado é um marcador NÃO-pagável.
- Mesmos endpoints e mesmos formatos de resposta. Toda resposta traz
  `livemode: false` (chaves de produção trazem `livemode: true`).
- Simule o pagamento: `POST /api/v1/test/charges/{id}/pay` (só aceita chave de
  teste). A cobrança vira `paid`, a taxa é calculada com as taxas REAIS da sua
  conta e o webhook `transaction.paid` é disparado com `livemode: false` no
  `data` — filtre pelo campo antes de dar baixa em pedidos reais.
- Chave de teste pode ser criada ANTES da aprovação do cadastro (KYC) — dá para
  integrar enquanto a conta está em análise.
- Não suportado em teste: `splits` (400), saques/`transfers` (403
  `test_mode_unsupported`). `GET /balance` responde zeros com `livemode: false`.

```
# criar cobrança de teste
curl -X POST https://app.xflowpayments.com/api/v1/charges \
  -u "pk_test_...:sk_test_..." \
  -H "Content-Type: application/json" \
  -d '{"amountCents":1990,"customer":{"name":"Cliente","email":"c@ex.com","document":"00000000000"}}'

# simular o pagamento (dispara o webhook transaction.paid com livemode:false)
curl -X POST https://app.xflowpayments.com/api/v1/test/charges/{id}/pay \
  -u "pk_test_...:sk_test_..."
```

## Recursos

### Criar uma cobrança Pix

```
POST /api/v1/charges
Authorization: Basic base64(pk_live_...:sk_live_...)
Content-Type: application/json
Idempotency-Key: pedido-1234   (opcional, recomendado — retry seguro)

{
  "amountCents": 1990,
  "customer": {
    "name": "Cliente Exemplo",
    "email": "cliente@exemplo.com",
    "document": "00000000000"
  },
  "description": "Pedido #1234",
  "external_reference": "pedido-1234"
}
```

`external_reference` (opcional, até 120 chars): o ID do pedido NO SEU sistema —
volta em toda resposta/webhook da cobrança e é pesquisável
(`GET /api/v1/charges?external_reference=pedido-1234`). Não precisa ser único.

Resposta `201 Created`:

```
{
  "id": "clx...",
  "livemode": true,
  "status": "pending",
  "amountCents": 1990,
  "external_reference": "pedido-1234",
  "pix": {
    "copyPaste": "00020101br.gov.bcb.pix...",
    "qrCodeBase64": "data:image/png;base64,iVBORw0..."
  },
  "createdAt": "2026-07-19T21:00:00.000Z"
}
```

Use `pix.copyPaste` para o copia-e-cola e `pix.qrCodeBase64` (PNG data-URL,
pronto para `<img src>`) para exibir o QR ao pagador — sem gerar QR no seu lado.
Ambos vêm no `201` e no `GET /charges/{id}` (a listagem não os inclui).

### Consultar o status de uma cobrança

```
GET /api/v1/charges/{id}
Authorization: Basic base64(pk_live_...:sk_live_...)
```

Resposta `200 OK` com o mesmo formato acima. Faça polling do `status` até `paid`.

Status possíveis: `pending`, `paid`, `expired`, `canceled`, `refunded`,
`under_review`, `blocked`.

### Split de pagamentos (entre sellers do sistema)

Envie `splits` no corpo de `POST /api/v1/charges`. Quando o pagamento confirma, cada
recebedor é creditado no saldo dele; a reserva incide só sobre o que sobra para você.
`recipient_id` = id da empresa recebedora. `PERCENTAGE` usa `percent` (% do líquido);
`FIXED` usa `amount` (centavos). Soma dos percentuais ≤ 100. Split inválido → `400`.

```
"splits": [
  { "recipient_id": "id-do-seller-B", "type": "PERCENTAGE", "percent": 40 },
  { "recipient_id": "id-do-seller-C", "type": "FIXED", "amount": 1500 }
]
```

### Listar cobranças (filtros + paginação por cursor)

```
GET /api/v1/charges?limit=50&status=paid&external_reference=pedido-1234&from=2026-07-01&to=2026-07-31&starting_after=<id>
Authorization: Basic base64(pk_live_...:sk_live_...)
```
Todos os parâmetros são opcionais. `status`: pending|paid|expired|canceled|under_review|refunded|blocked.
`from`/`to`: datas ISO. `starting_after`: id da última cobrança da página anterior (cursor).
Resposta `200`: `{ "data": [ { id, status, amountCents, description, external_reference, customer, createdAt } ], "has_more": true }`.
Enquanto `has_more` for true, repita com `starting_after` = id do último item.

### Saldo

```
GET /api/v1/balance
Authorization: Basic base64(pk_live_...:sk_live_...)
```
Resposta `200`: `{ "available": 12345, "reserved": 0, "currency": "BRL" }` (centavos).

### Dados da conta

```
GET /api/v1/company
Authorization: Basic base64(pk_live_...:sk_live_...)
```
Resposta `200` (objeto plano; `document` vem mascarado):
`{ id, legal_name, trade_name, document, status, kyc_status,
balance: { available, reserved, currency }, fees: { pixPercent, pixFixedCents,
reservePercent, reserveDays, withdrawalPercent, withdrawalFixedCents }, created_at }`.

### Solicitar saque (Pix out)

```
POST /api/v1/transfers
Authorization: Basic base64(pk_live_...:sk_live_...)
Content-Type: application/json
Idempotency-Key: saque-1234   (opcional, recomendado — retry seguro, evita saque duplicado)

{ "amount": 500, "pix_key": "12345678909", "pix_key_type": "CPF", "message": "opcional" }
```
`pix_key_type`: `CPF`, `CNPJ`, `EMAIL`, `PHONE` ou `EVP`. `message` (opcional): observação livre. Resposta `201`:
`{ "data": { id, status, amount, net_amount, fee, pix_key, pix_key_type, pending_approval, created_at } }`.
Com "saque automático" desligado na conta, o saque nasce pendente de aprovação
(`pending_approval: true`). Consulte com `GET /api/v1/transfers/{id}` ou liste em
`GET /api/v1/transfers?status=completed&from=...&to=...&starting_after=<id>` —
mesma paginação por cursor das cobranças (resposta inclui `has_more`).
Envie `Idempotency-Key` (único por saque): no retry, o MESMO saque é devolvido em
vez de debitar o saldo de novo. Se o saque via API estiver desabilitado para a sua
conta, o retorno é `403 api_withdrawal_disabled`.

## Erros

Respostas de erro usam JSON `{ "error": "<codigo>", "message": "...", "request_id": "req_..." }`
(cite o `request_id` ao abrir chamado — ele aparece também em Integrações > Logs da API):

- `401 unauthorized` — chave ausente, inválida, revogada — ou IP fora da allowlist da conta.
- `400 validation` — corpo inválido (ver `details`).
- `403 account_inactive` — conta em análise ou bloqueada.
- `403 api_withdrawal_disabled` — saque via API desabilitado para esta conta.
- `403 test_mode_unsupported` — recurso indisponível com chave de teste (ex.: saques).
- `403 test_mode_only` — endpoint de simulação chamado com chave de produção.
- `404 not_found` — cobrança/saque não encontrado.
- `422 transfer_failed` — saque recusado (ex.: saldo insuficiente).
- `409 idempotency_conflict` — a `Idempotency-Key` já foi usada com um corpo
  DIFERENTE. A mesma chave só vale para retry do MESMO pedido; use outra chave.
- `429 rate_limited` — muitas requisições; aguarde o valor (segundos) do header
  `retry-after` e tente de novo com backoff. O limite é por conta. Toda resposta
  autenticada traz os headers `x-ratelimit-limit`, `x-ratelimit-remaining` e
  `x-ratelimit-reset` (epoch em segundos) — monitore-os para não tomar 429.
- `502 charge_failed` — falha ao gerar a cobrança no provedor.

## Exemplo (curl)

```
curl -X POST https://app.xflowpayments.com/api/v1/charges \
  -u "pk_live_...:sk_live_..." \
  -H "Content-Type: application/json" \
  -d '{"amountCents":1990,"customer":{"name":"Cliente","email":"c@ex.com","document":"00000000000"}}'
```

## Webhooks

Cadastre endpoints em Integrações > Webhooks. A cada evento assinado enviamos um
`POST` JSON para a sua URL, com os headers `x-webhook-event` e
`x-webhook-signature: sha256=<HMAC-SHA256 do corpo cru, com o segredo do endpoint>`.
Reenviamos até 3 vezes; responda `2xx` para confirmar. Corpo: `{ event, data, createdAt }`.

Eventos disponíveis:
- `transaction.pending` — Aguardando pagamento (cobrança criada).
- `transaction.paid` — Pago.
- `transaction.refunded` — Reembolsado.
- `withdrawal.processing` — Saque processando.
- `withdrawal.completed` — Saque concluído.
- `withdrawal.failed` — Saque falhou.

MED (contestações Pix — disparados quando o seller tem MED ativo):
- `dispute.opened` — infração aberta (responda a defesa no painel > Disputas dentro do prazo).
- `dispute.accepted` — defesa aceita (disputa ganha).
- `dispute.rejected` — defesa rejeitada (disputa perdida).

### Gerenciar endpoints via API

Além do painel, os endpoints podem ser geridos pela própria API (funciona com
chave de produção E de teste — o endpoint é o mesmo; eventos de teste chegam
com `livemode: false` no `data`):

```
GET    /api/v1/webhooks               → { "data": [ { id, url, events, status, secret_masked, ... } ] }
POST   /api/v1/webhooks               → cria; corpo: { "url": "https://...", "events": ["transaction.paid"], "description"?: "..." }
                                        resposta 201 inclui `secret` (assinatura HMAC) UMA ÚNICA VEZ — guarde-o.
PATCH  /api/v1/webhooks/{id}          → { "active": true|false } pausa/reativa
DELETE /api/v1/webhooks/{id}          → remove o endpoint
```

Regras: URL obrigatoriamente `https://` e pública (rede interna → `400
invalid_url`); eventos devem ser da lista acima; máximo de 20 endpoints por
conta (`400 endpoint_limit`).

## Boas práticas

- Trate valores sempre em centavos (inteiros).
- Prefira webhooks para saber que uma cobrança foi paga; use polling do status (GET) como alternativa.
- Envie `Idempotency-Key` (único por pedido) ao criar: no retry, a MESMA cobrança é
  devolvida em vez de gerar outro Pix. Reusar a chave com um corpo DIFERENTE
  retorna `409 idempotency_conflict` (funciona igual no modo de teste).
- Use `description` para a sua referência (ex.: "Pedido #1234"); ela fica salva na cobrança.

## Em breve

- Checkout hospedado (link de pagamento).
- Cartão de crédito, boleto, 3DS e assinaturas (exigem engines próprias).
