# BrasaPay 4.0 — registro de progresso

A Onda 1 está em produção desde 28/10/2026 (main). A Onda 2 também está em produção desde 28/10/2026. A Onda 3 está na branch `feat/brasapay-4-onda3`, ainda sem deploy.

## Onda 1 — confiabilidade financeira e operacional

| Item | Status | Onde |
|---|---|---|
| Correções críticas anteriores (renovação, saque ambíguo, MED, split) | Em produção (28/10) | `RenewalController`, `MerchantWithdrawalService::markReconcileExhausted`, `PlatformOrderAdminService`, `RevenueSplitCalculator` |
| KPIs financeiros: custo de saque (`paid`) e taxa de cada parte da venda uma vez | Implementado e testado | `PlatformRevenueKpis`, `MerchantRevenueBreakdownService`, escopo `WalletTransaction::originalSaleFeeLines` |
| Brasa Treasury v1 (conciliação, modo observação) | Implementado e testado | `TreasuryReconciliationService`, `treasury:reconcile` (hora em hora), `/plataforma/tesouraria` |
| Monitoramento de adquirentes | Implementado e testado | `acquirer_attempts`, `AcquirerAttemptRecorder`, `AcquirerHealthService`, `/plataforma/ops/adquirentes` |
| Proteção contra cobrança dupla no cartão (timeout não cai para outra adquirente) | Implementado e testado | `PaymentService::createCardPayment`, `AmbiguousCardChargeException` |
| Brasa Smart Routing | Implementado e testado — **desligado por padrão** | `SmartRoutingService`, `PaymentService::getGatewayOrderForMethod` |
| BrasaShield (modo observação) | Implementado e testado | `BrasaShieldService`, `shield:scan` (10 min), `/plataforma/risco` |
| Command Center v1 | Implementado e testado | `CommandCenterController`, `/plataforma/central` |
| Infraestrutura: servidor web de produção | Em produção (28/10): nginx + PHP-FPM (pool fixo de 12); `GETFY_WEB_SERVER=artisan` volta ao artisan serve sem rebuild | `Dockerfile`, `docker/start-web.sh`, `docker/nginx/default.conf`, `docker/php-fpm/zz-getfy.conf` |

### Migrations (só tabelas novas)
- `2026_10_29_100000_create_treasury_tables` — `treasury_discrepancies`, `treasury_runs`
- `2026_10_29_110000_create_acquirer_attempts_table` — `acquirer_attempts` (retenção 30 dias)
- `2026_10_29_120000_create_risk_assessments_table` — `risk_assessments`

### Agendamentos novos
- `treasury:reconcile` — de hora em hora (minuto 17)
- `shield:scan --minutes=30` — a cada 10 minutos
- `acquirer:prune-attempts --days=30` — diário 04:10

### Garantias
- Treasury e BrasaShield não movimentam saldo nem bloqueiam pagamento; mudanças de status exigem justificativa e vão para `platform_audit_logs`.
- Treasury só encerra automaticamente divergências que reverificou (janela padrão, consulta não truncada, objeto dentro da janela).
- Smart Routing só reordena; a política de adquirentes do seller é aplicada depois; produto/API com adquirente fixada não é reordenado; amostra pequena não decide.
- Falha do registro de tentativas ou do roteamento nunca interrompe a cobrança.

### Testes
`tests/Feature/{PlatformRevenueKpisTest,TreasuryReconciliationTest,SmartRoutingTest,BrasaShieldAndCommandCenterTest}.php` (19 testes). Suíte completa sem regressões em relação à lista de falhas pré-existentes.

### Pendências da Onda 1
- Treasury v2: confronto com extratos das adquirentes (depende das APIs de cada uma) e "saldo da carteira × lançamentos".
- Smart Routing: só ligar depois de alguns dias de telemetria (amostra mínima por adquirente/método).
- BrasaShield: calibrar pesos com os dados reais antes de qualquer ação automática.

## Onda 2 — crescimento do seller

Branch: `feat/brasapay-4-onda2`, em produção desde 28/10/2026 (main 53c8357).

| Item | Status | Onde |
|---|---|---|
| Brasa Recovery (e-mail) | Em produção — **desligado por padrão** em cada conta | `BrasaRecoveryService`, `recovery:process-email` (5 min), `/recuperacao`, descadastro assinado |
| Brasa CRM | Em produção | `CrmService`, `/crm`, `/crm/contato/{key}` |
| Brasa Subscriptions | Em produção | `CustomerSubscriptionsController` (`/painel-cliente/assinaturas`), `SubscriptionMetricsService` |
| Brasa Experiments (A/B da landing) | Em produção | `ExperimentService`, `ExperimentsController`, `/experimentos` |
| Brasa Intelligence | Em produção | `IntelligenceService`, `IntelligenceController`, `/inteligencia` |

### Migrations (tabelas novas + colunas anuláveis)
- `2026_10_30_100000_create_recovery_tables` — `recovery_messages`, `recovery_opt_outs`
- `2026_10_30_110000_create_crm_contact_meta_table` — `crm_contact_meta`
- `2026_10_30_120000_add_self_service_cancel_to_subscriptions` — 4 colunas novas em `subscriptions` (padrão falso/nulo)
- `2026_10_30_130000_create_experiments_tables` — `experiments`, `experiment_assignments`
- `2026_10_30_140000_create_ad_spends_table` — `ad_spends`

### Agendamentos novos
- `recovery:process-email` — a cada 5 minutos (só age em contas que ligaram a recuperação)

### Garantias
- Recovery envia só pelo SMTP do próprio seller, respeita descadastro, horário de silêncio e limite diário, nunca repete a mesma etapa e só atribui recuperação a pedido pago depois do envio.
- CRM deriva os contatos dos pedidos (sem segunda base) e respeita os produtos liberados para a equipe.
- Cancelamento pelo assinante vale no fim do período já pago; renovação automática e lembretes param para essas assinaturas.
- MRR normalizado pelo intervalo do plano (antes somava o preço cheio de qualquer plano).
- Experiments: o visitante sempre vê a mesma variante; conversão = venda paga; vencedora só com amostra mínima (200 sessões por variante, 20 vendas pagas) e p < 0,05; aplicar vencedora exige esse critério; falha no experimento volta para a landing publicada.
- Intelligence: receita só de pagamento confirmado; ROAS e CAC só onde o seller informou custo; alertas são regras com os números junto, sem ação automática; equipe não vê custo (vale para a conta inteira).

### Testes
`tests/Feature/{BrasaRecoveryTest,BrasaCrmTest,BrasaSubscriptionsTest,BrasaExperimentsTest,BrasaIntelligenceTest}.php` (19 testes). Suíte completa (1780 testes) sem falhas novas em relação à lista pré-existente.

### Pendências da Onda 2
- Recovery por WhatsApp/SMS (hoje só e-mail; já existe o relatório da Uazapi).
- Intelligence: importar custo direto das contas de anúncio (hoje é cadastro manual).
- Experiments: testar outros elementos além da landing (preço, order bump).

## Onda 3 — plataforma e expansão

Branch: `feat/brasapay-4-onda3`. Implementada e testada no sandbox; **sem deploy** (aguarda autorização).

| Item | Status | Onde |
|---|---|---|
| Brasa Developers | Implementado e testado | Sandbox `/api/sandbox/v1` (chaves `gpk_test_`/`gsk_test_`), `LogApiRequest`, portal `/desenvolvedores`, `OpenApiSpec` (`/docs/api-pagamentos/openapi.json`) |
| Biblioteca de templates (Brasa Marketplace) | Implementado e testado | `TemplateLibraryService`, `/biblioteca`, admin `/plataforma/biblioteca` |
| Brasa Enterprise (white-label) | Implementado e testado | `EnterpriseService`, `ResolveWhiteLabelHost` + `EnforceWhiteLabelOwnership`, `/marca-e-dominio`, admin `/plataforma/enterprise` |
| Brasa AI | Implementado e testado — **desligado até o admin configurar a chave** | `AiAssistantService`, `AiToolbox`, `AnthropicProvider` (SDK oficial `anthropic-ai/sdk`), `/assistente`, admin `/plataforma/assistente-ia` |
| Command Center (recursos avançados) | Implementado e testado | `CommandCenterService`, `ops:evaluate-alerts`, `/plataforma/central` |

### Migrations
- `2026_11_01_100000_brasa_developers` — colunas novas com padrão: `api_keys.environment` (`live`), `api_webhook_deliveries.duration_ms`/`livemode`, `api_application_webhooks.environment` (`live`); tabelas `api_request_logs`, `sandbox_transactions`
- `2026_11_01_110000_create_marketplace_tables` — `marketplace_templates`, `marketplace_template_versions`, `marketplace_installs`, `marketplace_reviews`
- `2026_11_01_120000_create_enterprise_tables` — `enterprise_accounts`, `enterprise_domains`
- `2026_11_01_130000_create_ai_assistant_tables` — `ai_conversations`, `ai_messages`
- `2026_11_01_140000_create_ops_alerts_table` — `ops_alerts`

### Dependência nova
- `anthropic-ai/sdk` ^1.3 (lock: 6 pacotes novos, nenhuma atualização de pacote existente)

### Agendamentos novos
- `ops:evaluate-alerts` — a cada 5 minutos
- `developers:prune --days=30 --sandbox-days=90` — diário 04:20
- `ai:prune --days=180` — diário 04:30
- `enterprise:verify-domains` — diário 05:10

### Garantias
- Sandbox: chave de sandbox nunca acessa `/api/v1` e vice-versa; nada cria pedido, cobrança, saldo ou saque; webhooks de teste vão só para endpoints de webhook de sandbox (secret próprio) com `livemode: false` e cabeçalho `X-Webhook-Livemode`.
- Registro de chamadas da API sem corpo, cabeçalhos nem credenciais; payload de webhook exibido com dados pessoais mascarados.
- OpenAPI só documenta rotas que existem (teste confere contra as rotas registradas).
- Templates passam por revisão; instalam pelo importador oficial com a mesma tela de prévia/confirmação; não levam aulas, comunidade, coprodução, credenciais, cupons, integrações nem compradores; pacote conferido por hash; templates de recuperação não aceitam links próprios; preço sempre 0.
- White-label muda só a apresentação (tenant financeiro, adquirentes e credenciais iguais). Domínio só ativa com TXT + HTTPS; nele abre só o checkout do próprio seller (mapa rota → recurso, negado por padrão); domínios da plataforma reservados; reverificação diária desativa quem perder DNS/HTTPS.
- AI só consulta, por ferramentas tipadas com schema estrito; tenant e produtos vêm do usuário logado; permissões de equipe iguais às telas; sem dados pessoais de compradores; resultados de ferramenta delimitados como dados; limites por usuário/dia, conta/mês e plataforma/mês; no máximo 1 pergunta por conta e 3 na plataforma ao mesmo tempo; prazo total de 90 s.
- Command Center: alertas só de números reais, com histórico; reprocessamento manual só de jobs de notificação/rastreamento (cobrança, saque e conciliação ficam nas telas próprias); tudo auditado.

### Testes
`tests/Feature/{BrasaDevelopersTest,BrasaMarketplaceTest,BrasaEnterpriseTest,BrasaAiTest,BrasaCommandCenterTest}.php` (30 testes). Suíte completa (1810 testes) sem falhas novas em relação à lista pré-existente.

### Pendências da Onda 3
- Enterprise: no domínio próprio, fluxos que redirecionam para PayPal, CajuPay SDK e Lina continuam no domínio da plataforma; e-mails transacionais com a marca do cliente ainda não.
- Marketplace: templates pagos (arquitetura pronta, sem cobrança até definir a operação comercial e fiscal); prévia visual da landing.
- AI: explicações por IA no Command Center; histórico mais longo de conversa.
- Developers: métricas de consumo por chave; sandbox para assinaturas.
