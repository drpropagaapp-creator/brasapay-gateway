<?php

namespace App\Support;

/**
 * Especificação OpenAPI 3.1 da API de pagamentos — só endpoints que existem em routes/api.php
 * (um teste confere cada caminho contra as rotas registradas).
 */
final class OpenApiSpec
{
    /** @return array<string, mixed> */
    public static function build(string $baseUrl): array
    {
        $baseUrl = rtrim($baseUrl, '/');
        $paths = [];
        foreach (self::operations() as [$method, $path, $scope, $summary, $body]) {
            $op = [
                'summary' => $summary,
                'tags' => [str_starts_with($path, '/sandbox') ? 'Sandbox' : ucfirst(explode('/', trim($path, '/'))[1] ?? 'API')],
                'security' => [['PublicKey' => [], 'SecretKey' => []]],
                'description' => 'Escopo necessário: `'.$scope.'`.',
                'responses' => [
                    '200' => ['description' => 'OK'],
                    '401' => ['description' => 'Chave ausente ou inválida'],
                    '403' => ['description' => 'Sem permissão (escopo, IP ou ambiente da chave)'],
                    '422' => ['description' => 'Dados inválidos ou operação recusada'],
                    '429' => ['description' => 'Limite de requisições'],
                ],
            ];
            preg_match_all('/\{(\w+)\}/', $path, $m);
            foreach ($m[1] as $param) {
                $op['parameters'][] = ['name' => $param, 'in' => 'path', 'required' => true, 'schema' => ['type' => 'string']];
            }
            if (in_array($method, ['post', 'put'], true) && str_contains($path, 'payments/')) {
                $op['parameters'][] = ['name' => 'Idempotency-Key', 'in' => 'header', 'required' => false, 'schema' => ['type' => 'string', 'maxLength' => 128]];
            }
            if ($body !== null) {
                $op['requestBody'] = ['required' => true, 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/'.$body]]]];
            }
            $paths[$path][$method] = $op;
        }

        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'API de pagamentos',
                'version' => '1.0.0',
                'description' => "Produção em /api/v1 com chaves gpk_/gsk_. Sandbox em /api/sandbox/v1 com chaves gpk_test_/gsk_test_ (sem efeito financeiro; webhooks com \"livemode\": false).\n\nWebhooks: cabeçalho X-Webhook-Signature = HMAC-SHA256 do corpo bruto com o secret do endpoint; X-Webhook-Id identifica o evento (use para deduplicar); X-Webhook-Livemode = true|false. Eventos de sandbox só são entregues em endpoints de webhook de sandbox, com o secret deles.",
            ],
            'servers' => [['url' => $baseUrl.'/api']],
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'PublicKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Public-Key'],
                    'SecretKey' => ['type' => 'apiKey', 'in' => 'header', 'name' => 'X-Secret-Key'],
                ],
                'schemas' => self::schemas(),
            ],
        ];
    }

    /** @return list<array{0:string,1:string,2:string,3:string,4:string|null}> método, caminho, escopo, resumo, corpo */
    public static function operations(): array
    {
        $live = [
            ['get', '/v1/payments', 'payments:read', 'Listar pagamentos', null],
            ['post', '/v1/payments/pix', 'payments:write', 'Criar cobrança Pix', 'PixRequest'],
            ['post', '/v1/payments/card', 'payments:write', 'Criar cobrança no cartão', 'CardRequest'],
            ['post', '/v1/payments/boleto', 'payments:write', 'Criar boleto', 'PixRequest'],
            ['get', '/v1/payments/{order}', 'payments:read', 'Consultar pagamento', null],
            ['post', '/v1/pix/{order}/cancel', 'payments:refund', 'Cancelar Pix pendente', null],
            ['post', '/v1/pix/{order}/refund', 'payments:refund', 'Reembolsar Pix', null],
            ['get', '/v1/balance', 'withdrawals:read', 'Consultar saldo', null],
            ['get', '/v1/withdrawals', 'withdrawals:read', 'Listar saques', null],
            ['post', '/v1/withdrawals', 'withdrawals:write', 'Solicitar saque', 'WithdrawalRequest'],
            ['get', '/v1/withdrawals/{withdrawal}', 'withdrawals:read', 'Consultar saque', null],
            ['put', '/v1/payout-destination', 'withdrawals:write', 'Definir destino dos saques', null],
            ['get', '/v1/webhook', 'webhooks:read', 'Ver configuração de webhook', null],
            ['put', '/v1/webhook', 'webhooks:write', 'Atualizar configuração de webhook', null],
            ['post', '/v1/webhook/rotate-secret', 'webhooks:write', 'Gerar novo secret do webhook', null],
            ['post', '/v1/webhooks/deliveries/{deliveryId}/retry', 'payments:read', 'Reenviar uma entrega de webhook', null],
        ];
        $sandbox = [
            ['get', '/sandbox/v1/payments', 'payments:read', 'Listar pagamentos de teste', null],
            ['post', '/sandbox/v1/payments/pix', 'payments:write', 'Criar Pix de teste', 'PixRequest'],
            ['post', '/sandbox/v1/payments/card', 'payments:write', 'Cartão de teste (token tok_sandbox_declined recusa)', 'CardRequest'],
            ['post', '/sandbox/v1/payments/boleto', 'payments:write', 'Criar boleto de teste', 'PixRequest'],
            ['get', '/sandbox/v1/payments/{id}', 'payments:read', 'Consultar pagamento de teste', null],
            ['post', '/sandbox/v1/simulate/payments/{id}/{action}', 'payments:write', 'Simular approve | expire | refund', null],
            ['get', '/sandbox/v1/balance', 'withdrawals:read', 'Saldo de teste', null],
            ['post', '/sandbox/v1/withdrawals', 'withdrawals:write', 'Saque de teste', 'WithdrawalRequest'],
            ['get', '/sandbox/v1/withdrawals/{id}', 'withdrawals:read', 'Consultar saque de teste', null],
            ['post', '/sandbox/v1/simulate/withdrawals/{id}/{action}', 'withdrawals:write', 'Simular process | pay | fail', null],
            ['post', '/sandbox/v1/webhooks/test', 'webhooks:write', 'Enviar evento de teste aos webhooks', 'TestWebhookRequest'],
        ];

        return array_merge($live, $sandbox);
    }

    /** @return array<string, mixed> */
    private static function schemas(): array
    {
        $customer = ['type' => 'object', 'required' => ['email'], 'properties' => [
            'email' => ['type' => 'string', 'format' => 'email'], 'name' => ['type' => 'string'],
            'cpf' => ['type' => 'string'], 'phone' => ['type' => 'string'],
        ]];

        return [
            'PixRequest' => ['type' => 'object', 'required' => ['customer', 'amount'], 'properties' => [
                'customer' => $customer, 'amount' => ['type' => 'number', 'example' => 97.0],
                'currency' => ['type' => 'string', 'enum' => ['BRL', 'USD', 'EUR']], 'product_id' => ['type' => 'string'],
                'metadata' => ['type' => 'object'], 'idempotency_key' => ['type' => 'string'],
            ]],
            'CardRequest' => ['type' => 'object', 'required' => ['customer', 'amount', 'card'], 'properties' => [
                'customer' => $customer, 'amount' => ['type' => 'number'],
                'card' => ['type' => 'object', 'required' => ['payment_token'], 'properties' => ['payment_token' => ['type' => 'string']]],
                'metadata' => ['type' => 'object'], 'idempotency_key' => ['type' => 'string'],
            ]],
            'WithdrawalRequest' => ['type' => 'object', 'required' => ['amount', 'pix_key', 'pix_key_type'], 'properties' => [
                'amount' => ['type' => 'number'], 'pix_key' => ['type' => 'string'],
                'pix_key_type' => ['type' => 'string', 'enum' => ['cpf', 'cnpj', 'email', 'phone', 'evp', 'random']],
                'notes' => ['type' => 'string'], 'idempotency_key' => ['type' => 'string'],
            ]],
            'TestWebhookRequest' => ['type' => 'object', 'required' => ['event'], 'properties' => [
                'event' => ['type' => 'string', 'enum' => \App\Http\Controllers\Api\Sandbox\SandboxController::testEvents()],
            ]],
        ];
    }
}
