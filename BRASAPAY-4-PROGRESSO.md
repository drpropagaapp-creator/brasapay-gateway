# BrasaPay 4.0 — registro de progresso

Branch: `feat/brasapay-4-onda1`. Nada desta branch está em produção até autorização explícita.

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
| Infraestrutura: trocar `php artisan serve` por servidor de produção | Não iniciado — exige plano de deploy próprio | `Dockerfile` (CMD) |

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
- Servidor web de produção (php-fpm/nginx, FrankenPHP ou Octane) — plano e janela de manutenção.
- Treasury v2: confronto com extratos das adquirentes (depende das APIs de cada uma) e "saldo da carteira × lançamentos".
- Smart Routing: só ligar depois de alguns dias de telemetria (amostra mínima por adquirente/método).
- BrasaShield: calibrar pesos com os dados reais antes de qualquer ação automática.

## Ondas 2 e 3
Não iniciadas.
