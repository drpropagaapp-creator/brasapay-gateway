<script setup>
import { computed, ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import PnPageHeader from '@/components/paynext/PnPageHeader.vue';
import PnKpiCard from '@/components/paynext/PnKpiCard.vue';
import PnPanel from '@/components/paynext/PnPanel.vue';
import PnEmptyState from '@/components/paynext/PnEmptyState.vue';
import {
    Terminal, X, Activity, Clock, Gauge, TriangleAlert, FlaskConical, BookOpen, FileJson, Play, KeyRound, Copy, Check, Send, Webhook, Route, Bug, ShieldCheck,
} from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

/**
 * Brasa Developers — consumo da API, erros, entregas de webhook, simulador e exemplos.
 * Números vêm do registro real de chamadas; sem chamadas, mostra zero (nunca estimativa).
 */
const props = defineProps({
    applications: { type: Array, default: () => [] },
    selected: { type: Number, default: null },
    overview: { type: Object, default: null },
    base_url: { type: String, required: true },
    test_events: { type: Array, default: () => [] },
    sandbox_declined_token: { type: String, default: '' },
});

const o = computed(() => props.overview);
const errorRate = computed(() => {
    const l = o.value?.live;
    return l && l.total ? ((l.client_errors + l.server_errors) / l.total) * 100 : null;
});
const fmtDate = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR') : '—');

function selectApp(id) {
    router.get('/desenvolvedores', { app: id }, { preserveScroll: true });
}

const detail = ref(null);
const detailLoading = ref(false);
async function openDelivery(d) {
    detailLoading.value = true;
    try {
        detail.value = (await window.axios.get(`/desenvolvedores/entregas/${d.id}`)).data;
    } finally {
        detailLoading.value = false;
    }
}
async function retry(d) {
    await window.axios.post(`/aplicacoes-api/${props.selected}/webhook/deliveries/${d.id}/retry`);
    router.reload({ only: ['overview'] });
}

const testEvent = ref('order.completed');
const testMsg = ref('');
async function sendTest() {
    testMsg.value = '';
    try {
        const r = await window.axios.post(`/desenvolvedores/${props.selected}/webhook-teste`, { event: testEvent.value });
        testMsg.value = r.data.message;
        router.reload({ only: ['overview'] });
    } catch (e) {
        testMsg.value = e.response?.data?.message || 'Não foi possível enviar.';
    }
}

const lang = ref('curl');
const examples = computed(() => {
    const url = `${props.base_url}/api/sandbox/v1/payments/pix`;
    return {
        curl: `curl -X POST ${url} \\
  -H "X-Public-Key: gpk_test_..." \\
  -H "X-Secret-Key: gsk_test_..." \\
  -H "Content-Type: application/json" \\
  -H "Idempotency-Key: pedido-123" \\
  -d '{"amount": 97.00, "customer": {"email": "comprador@example.com"}}'`,
        php: `$res = (new \\GuzzleHttp\\Client())->post('${url}', [
    'headers' => ['X-Public-Key' => getenv('BP_PUBLIC_KEY'), 'X-Secret-Key' => getenv('BP_SECRET_KEY'), 'Idempotency-Key' => 'pedido-123'],
    'json' => ['amount' => 97.00, 'customer' => ['email' => 'comprador@example.com']],
]);
$pix = json_decode((string) $res->getBody(), true); // order_id, copy_paste, status

// Validar webhook: compare com o cabeçalho X-Webhook-Signature
$valid = hash_equals(hash_hmac('sha256', file_get_contents('php://input'), getenv('BP_WEBHOOK_SECRET')), $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '');`,
        js: `const res = await fetch('${url}', {
  method: 'POST',
  headers: { 'X-Public-Key': process.env.BP_PUBLIC_KEY, 'X-Secret-Key': process.env.BP_SECRET_KEY, 'Content-Type': 'application/json', 'Idempotency-Key': 'pedido-123' },
  body: JSON.stringify({ amount: 97.0, customer: { email: 'comprador@example.com' } }),
});
const pix = await res.json(); // order_id, copy_paste, status

// Validar webhook (Node): use o corpo BRUTO da requisição
import crypto from 'node:crypto';
const expected = crypto.createHmac('sha256', process.env.BP_WEBHOOK_SECRET).update(rawBody).digest('hex');
const valid = crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(req.headers['x-webhook-signature'] || ''));`,
        python: `import os, hmac, hashlib, requests

r = requests.post('${url}',
    headers={'X-Public-Key': os.environ['BP_PUBLIC_KEY'], 'X-Secret-Key': os.environ['BP_SECRET_KEY'], 'Idempotency-Key': 'pedido-123'},
    json={'amount': 97.00, 'customer': {'email': 'comprador@example.com'}})
pix = r.json()  # order_id, copy_paste, status

# Validar webhook: raw_body = bytes do corpo recebido
expected = hmac.new(os.environ['BP_WEBHOOK_SECRET'].encode(), raw_body, hashlib.sha256).hexdigest()
valid = hmac.compare_digest(expected, request.headers.get('X-Webhook-Signature', ''))`,
    };
});

/* ---- Apresentação ---- */
const LANGS = [['curl', 'cURL'], ['php', 'PHP'], ['js', 'JavaScript'], ['python', 'Python']];
const appName = computed(() => props.applications.find((a) => String(a.id) === String(props.selected))?.name || o.value?.app?.name || '');
const DELIVERY = {
    delivered: ['Entregue', 'pn-badge--success'],
    pending: ['Pendente', 'pn-badge--warning'],
    failed: ['Falhou', 'pn-badge--error'],
};
const deliveryLabel = (s) => DELIVERY[s]?.[0] || s;
const deliveryBadge = (s) => DELIVERY[s]?.[1] || 'pn-badge--neutral';
function codeTone(code) {
    if (code === null || code === undefined) return 'text-[var(--text-tertiary)]';
    if (code >= 500) return 'text-[var(--status-error-fg)]';
    if (code >= 400) return 'text-[var(--status-warning-fg)]';
    return 'text-[var(--status-success-fg)]';
}
const errorTone = (status) => (status >= 500 ? 'pn-badge--error' : 'pn-badge--warning');

const copiedCode = ref(false);
function copyCode() {
    navigator.clipboard?.writeText(examples.value[lang.value]);
    copiedCode.value = true;
    setTimeout(() => (copiedCode.value = false), 2000);
}
function closeDetail() {
    detail.value = null;
}
</script>

<template>
    <div class="space-y-6">
        <PnPageHeader eyebrow="Brasa Developers" title="Desenvolvedores" :icon="Terminal" description="Consumo da API, erros, entregas de webhook e sandbox.">
            <template #actions>
                <a href="/docs/api-pagamentos" target="_blank" rel="noopener" class="pn-btn pn-btn--secondary pn-btn--sm !w-auto"><BookOpen class="h-3.5 w-3.5" /> Documentação</a>
                <a href="/docs/api-pagamentos/openapi.json" target="_blank" rel="noopener" class="pn-btn pn-btn--secondary pn-btn--sm !w-auto"><FileJson class="h-3.5 w-3.5" /> OpenAPI</a>
                <a href="/docs/api-pagamentos/testar" target="_blank" rel="noopener" class="pn-btn pn-btn--secondary pn-btn--sm !w-auto"><Play class="h-3.5 w-3.5" /> Testar chamadas</a>
            </template>
        </PnPageHeader>

        <p class="-mt-2 flex flex-wrap items-center gap-1.5 text-xs text-[var(--text-tertiary)]">
            <KeyRound class="h-3.5 w-3.5" aria-hidden="true" /> Chaves e URLs de webhook ficam em
            <Link href="/aplicacoes-api" class="font-medium text-[var(--text-primary)] underline underline-offset-2">Aplicações API</Link>.
        </p>

        <PnEmptyState v-if="!applications.length" title="Nenhuma aplicação API ainda."
            description="Crie uma aplicação para gerar chaves de produção e de sandbox e cadastrar URLs de webhook." :icon="Terminal">
            <Link href="/aplicacoes-api" class="pn-btn pn-btn--primary pn-btn--sm !w-auto">Criar em Aplicações API</Link>
        </PnEmptyState>

        <template v-else-if="o">
            <!-- Aplicação selecionada -->
            <div class="pn-glass flex flex-wrap items-center justify-between gap-3 px-5 py-3">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[var(--ink-900)] text-[var(--lime-300)]"><KeyRound class="h-4 w-4" aria-hidden="true" /></span>
                    <div class="min-w-0">
                        <p class="text-[11px] uppercase tracking-[0.08em] text-[var(--text-tertiary)]">Aplicação</p>
                        <p class="truncate text-sm font-medium text-[var(--text-primary)]">{{ appName }}</p>
                    </div>
                    <span v-if="o.app" class="pn-badge shrink-0" :class="o.app.is_active ? 'pn-badge--success' : 'pn-badge--neutral'">{{ o.app.is_active ? 'Ativa' : 'Inativa' }}</span>
                </div>
                <div v-if="applications.length > 1" class="flex min-w-0 items-center gap-2">
                    <label for="dev-app" class="shrink-0 text-xs text-[var(--text-secondary)]">Trocar aplicação</label>
                    <select id="dev-app" :value="selected"
                        class="h-9 min-w-0 rounded-full border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 text-sm text-[var(--text-primary)] outline-none focus:ring-4 focus:ring-[var(--focus-ring)]"
                        @change="selectApp($event.target.value)">
                        <option v-for="a in applications" :key="a.id" :value="a.id">{{ a.name }}</option>
                    </select>
                </div>
            </div>

            <!-- Indicadores -->
            <section class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5" aria-label="Consumo da API">
                <PnKpiCard label="Chamadas (7 dias)" :value="Number(o.live.total) || 0" :icon="Activity" caption="em produção" :index="0" />
                <PnKpiCard label="Últimas 24h" :value="Number(o.live.last_24h) || 0" :icon="Clock" caption="em produção" :index="1" />
                <PnKpiCard label="Taxa de erro" :value="errorRate === null ? '—' : errorRate" :suffix="errorRate === null ? '' : '%'" :decimals="1" :icon="TriangleAlert"
                    caption="respostas 4xx e 5xx, 7 dias" :index="2" />
                <PnKpiCard label="Tempo p95" :value="o.live.p95_ms === null ? '—' : Number(o.live.p95_ms)" :suffix="o.live.p95_ms === null ? '' : ' ms'" :icon="Gauge"
                    caption="95% das chamadas abaixo disso" :index="3" />
                <PnKpiCard label="Chamadas no sandbox" :value="Number(o.sandbox.total) || 0" :icon="FlaskConical" caption="7 dias, sem efeito financeiro" :index="4"
                    class="col-span-2 md:col-span-1" />
            </section>

            <!-- Sandbox -->
            <PnPanel title="Sandbox" sub="Teste a integração de ponta a ponta antes de usar as chaves de produção." :index="5">
                <template #right><span class="pn-badge pn-badge--info shrink-0">sem efeito financeiro</span></template>
                <div class="grid gap-5 xl:grid-cols-[minmax(0,340px)_minmax(0,1fr)]">
                    <div class="min-w-0 space-y-4">
                        <ol class="space-y-3 text-sm text-[var(--text-secondary)]">
                            <li class="flex gap-3">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-[var(--bg-muted)] text-xs font-medium text-[var(--text-primary)]">1</span>
                                <p class="min-w-0">Crie uma chave de <strong class="font-medium text-[var(--text-primary)]">sandbox</strong> em Aplicações API (prefixo <code class="dev-code">gpk_test_</code>) e use os endpoints <code class="dev-code">/api/sandbox/v1</code>.</p>
                            </li>
                            <li class="flex gap-3">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-[var(--bg-muted)] text-xs font-medium text-[var(--text-primary)]">2</span>
                                <p class="min-w-0">Simule o resultado com <code class="dev-code">POST /api/sandbox/v1/simulate/payments/{id}/approve|expire|refund</code> e <code class="dev-code">/simulate/withdrawals/{id}/process|pay|fail</code>.</p>
                            </li>
                            <li class="flex gap-3">
                                <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-[var(--bg-muted)] text-xs font-medium text-[var(--text-primary)]">3</span>
                                <p class="min-w-0">No cartão, o token <code class="dev-code">{{ sandbox_declined_token }}</code> simula recusa.</p>
                            </li>
                        </ol>
                        <p class="flex items-start gap-2 rounded-2xl bg-[var(--status-info-bg)] px-3.5 py-2.5 text-xs text-[var(--status-info-fg)]">
                            <ShieldCheck class="mt-px h-4 w-4 shrink-0" aria-hidden="true" /> Nada ali gera cobrança, saldo ou saque real.
                        </p>
                        <dl class="grid grid-cols-2 gap-2">
                            <div class="rounded-2xl bg-[var(--bg-muted)]/60 p-3">
                                <dt class="text-[11px] text-[var(--text-tertiary)]">Chaves de sandbox ativas</dt>
                                <dd class="mt-0.5 text-lg font-medium tabular-nums text-[var(--text-primary)]">{{ o.sandbox.keys }}</dd>
                            </div>
                            <div class="rounded-2xl bg-[var(--bg-muted)]/60 p-3">
                                <dt class="text-[11px] text-[var(--text-tertiary)]">Transações de teste</dt>
                                <dd class="mt-0.5 text-lg font-medium tabular-nums text-[var(--text-primary)]">{{ o.sandbox.transactions }}</dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Exemplos de código -->
                    <div class="min-w-0 overflow-hidden rounded-[22px] bg-[var(--ink-900)] ring-1 ring-white/10">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-white/10 px-2.5 py-2">
                            <div class="pn-tabs !border-0 !bg-transparent !p-0" role="tablist" aria-label="Linguagem do exemplo">
                                <button v-for="l in LANGS" :key="l[0]" type="button" role="tab" :aria-selected="lang === l[0]"
                                    class="pn-tab !h-8 !px-3.5 !text-[12.5px] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--lime-300)]"
                                    :class="lang === l[0] ? 'pn-tab--active' : ''" @click="lang = l[0]">{{ l[1] }}</button>
                            </div>
                            <button type="button" class="inline-flex h-8 items-center gap-1.5 rounded-full px-3 text-xs text-white/70 transition hover:bg-white/10 hover:text-white focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--lime-300)]" @click="copyCode">
                                <Check v-if="copiedCode" class="h-3.5 w-3.5" /><Copy v-else class="h-3.5 w-3.5" /> {{ copiedCode ? 'Copiado' : 'Copiar' }}
                            </button>
                        </div>
                        <p class="px-4 pt-3 font-mono text-[11px] text-white/50">POST /api/sandbox/v1/payments/pix</p>
                        <pre class="max-h-[440px] overflow-auto px-4 pb-4 pt-2 text-xs leading-relaxed text-[#e8edf0]" role="tabpanel"><code>{{ examples[lang] }}</code></pre>
                    </div>
                </div>
            </PnPanel>

            <!-- Webhooks -->
            <PnPanel title="Webhooks" sub="Entregas recentes desta aplicação." :index="6">
                <template #right><Webhook class="h-4 w-4 shrink-0 text-[var(--text-tertiary)]" aria-hidden="true" /></template>
                <div class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_minmax(0,380px)]">
                    <div class="min-w-0 space-y-2 text-xs leading-relaxed text-[var(--text-secondary)]">
                        <p>Payload exibido com dados pessoais mascarados.</p>
                        <p>Assinatura: <code class="dev-code">X-Webhook-Signature</code> = HMAC-SHA256 do corpo; <code class="dev-code">X-Webhook-Livemode</code> diz se o evento é real.</p>
                        <p>Testes vão só para endpoints de sandbox.</p>
                    </div>
                    <div class="min-w-0 rounded-[22px] border border-[var(--border-strong)] bg-[var(--bg-muted)]/40 p-4">
                        <label for="dev-test-event" class="text-xs font-medium text-[var(--text-secondary)]">Evento de teste</label>
                        <div class="mt-2 flex flex-wrap gap-2">
                            <select id="dev-test-event" v-model="testEvent"
                                class="h-[34px] min-w-0 flex-1 rounded-full border border-[var(--border-strong)] bg-[var(--bg-surface)] px-3 font-mono text-xs text-[var(--text-primary)] outline-none focus:ring-4 focus:ring-[var(--focus-ring)]">
                                <option v-for="e in test_events" :key="e" :value="e">{{ e }}</option>
                            </select>
                            <button type="button" class="pn-btn pn-btn--primary pn-btn--sm !w-auto" :disabled="!o.has_sandbox_webhook" @click="sendTest"><Send class="h-3.5 w-3.5" /> Enviar evento de teste</button>
                        </div>
                        <p v-if="testMsg" class="mt-2 text-xs text-[var(--text-primary)]" role="status">{{ testMsg }}</p>
                        <p v-if="!o.has_sandbox_webhook" class="mt-2 text-xs text-[var(--text-tertiary)]">Eventos de teste vão só para endpoints de webhook de sandbox. Cadastre um em Aplicações API.</p>
                    </div>
                </div>

                <div class="relative mt-5 overflow-x-auto">
                    <table class="w-full min-w-[760px] text-sm">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-[0.06em] text-[var(--text-tertiary)]">
                                <th scope="col" class="pb-2 pr-3 font-medium">Evento</th>
                                <th scope="col" class="pb-2 pr-3 font-medium">Ambiente</th>
                                <th scope="col" class="pb-2 pr-3 font-medium">Status</th>
                                <th scope="col" class="pb-2 pr-3 font-medium">HTTP</th>
                                <th scope="col" class="pb-2 pr-3 font-medium">Tentativas</th>
                                <th scope="col" class="pb-2 pr-3 font-medium">Tempo</th>
                                <th scope="col" class="pb-2 pr-3 font-medium">Quando</th>
                                <th scope="col" class="pb-2"><span class="sr-only">Ações</span></th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="d in o.deliveries" :key="d.id" class="border-t border-[var(--border-strong)] text-[var(--text-primary)]">
                                <td class="py-2.5 pr-3 font-mono text-xs">{{ d.event }}</td>
                                <td class="pr-3"><span class="pn-badge pn-badge--nodot" :class="d.livemode ? 'pn-badge--neutral' : 'pn-badge--warning'">{{ d.livemode ? 'produção' : 'sandbox' }}</span></td>
                                <td class="pr-3"><span class="pn-badge" :class="deliveryBadge(d.status)">{{ deliveryLabel(d.status) }}</span></td>
                                <td class="pr-3 font-mono text-xs tabular-nums" :class="codeTone(d.code)">{{ d.code ?? '—' }}</td>
                                <td class="pr-3 tabular-nums">{{ d.attempt }}</td>
                                <td class="pr-3 text-xs tabular-nums">{{ d.duration_ms === null ? '—' : d.duration_ms + ' ms' }}</td>
                                <td class="pr-3 text-xs text-[var(--text-secondary)]">{{ fmtDate(d.at) }}</td>
                                <td class="whitespace-nowrap py-2 text-right">
                                    <button type="button" class="pn-btn pn-btn--ghost pn-btn--sm !h-8 !w-auto !px-3" @click="openDelivery(d)">Detalhes</button>
                                    <button v-if="d.status !== 'delivered'" type="button" class="pn-btn pn-btn--secondary pn-btn--sm ml-1 !h-8 !w-auto !px-3" @click="retry(d)">Reenviar</button>
                                </td>
                            </tr>
                            <tr v-if="!o.deliveries.length"><td colspan="8" class="py-8 text-center text-sm text-[var(--text-tertiary)]">Nenhuma entrega ainda.</td></tr>
                        </tbody>
                    </table>
                </div>
            </PnPanel>

            <div class="grid gap-6 lg:grid-cols-2">
                <!-- Rotas -->
                <PnPanel title="Rotas mais usadas (7 dias)" sub="As 10 rotas com mais chamadas no período." :index="7">
                    <template #right><Route class="h-4 w-4 shrink-0 text-[var(--text-tertiary)]" aria-hidden="true" /></template>
                    <div class="relative overflow-x-auto">
                        <table class="w-full min-w-[440px] text-sm">
                            <thead>
                                <tr class="text-left text-[11px] uppercase tracking-[0.06em] text-[var(--text-tertiary)]">
                                    <th scope="col" class="pb-2 pr-3 font-medium">Rota</th>
                                    <th scope="col" class="pb-2 pr-3 text-right font-medium">Chamadas</th>
                                    <th scope="col" class="pb-2 pr-3 text-right font-medium">Erros</th>
                                    <th scope="col" class="pb-2 text-right font-medium">Média</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="r in o.routes" :key="r.method + r.route" class="border-t border-[var(--border-strong)] text-[var(--text-primary)]">
                                    <td class="py-2.5 pr-3">
                                        <span class="mr-2 inline-flex rounded-md bg-[var(--bg-muted)] px-1.5 py-0.5 font-mono text-[10.5px] font-medium">{{ r.method }}</span>
                                        <span class="font-mono text-xs">/{{ r.route }}</span>
                                    </td>
                                    <td class="pr-3 text-right tabular-nums">{{ r.total }}</td>
                                    <td class="pr-3 text-right tabular-nums" :class="r.errors ? 'text-[var(--status-error-fg)]' : 'text-[var(--text-tertiary)]'">{{ r.errors }}</td>
                                    <td class="text-right text-xs tabular-nums text-[var(--text-secondary)]">{{ r.avg_ms }} ms</td>
                                </tr>
                                <tr v-if="!o.routes.length"><td colspan="4" class="py-8 text-center text-sm text-[var(--text-tertiary)]">Sem chamadas no período.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </PnPanel>

                <!-- Erros -->
                <PnPanel title="Erros recentes" sub="Últimas respostas 4xx e 5xx da API." :index="8">
                    <template #right><Bug class="h-4 w-4 shrink-0 text-[var(--text-tertiary)]" aria-hidden="true" /></template>
                    <ul class="max-h-[520px] divide-y divide-[var(--border-strong)] overflow-y-auto">
                        <li v-for="e in o.errors" :key="e.id" class="flex gap-3 py-3">
                            <span class="pn-badge pn-badge--nodot shrink-0 font-mono" :class="errorTone(e.status)">{{ e.status }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <span class="break-all font-mono text-xs text-[var(--text-primary)]">{{ e.method }} /{{ e.route }}</span>
                                    <span v-if="e.environment === 'sandbox'" class="pn-badge pn-badge--warning pn-badge--nodot !h-5 !px-2 !text-[10.5px]">sandbox</span>
                                </p>
                                <p class="mt-0.5 text-xs text-[var(--text-secondary)]">{{ e.error || 'Sem mensagem' }}</p>
                                <p class="mt-0.5 text-[11px] text-[var(--text-tertiary)]">{{ fmtDate(e.at) }}<template v-if="e.request_id"> · X-Request-Id <span class="break-all font-mono">{{ e.request_id }}</span></template></p>
                            </div>
                        </li>
                        <li v-if="!o.errors.length" class="py-8 text-center text-sm text-[var(--text-tertiary)]">Nenhum erro registrado.</li>
                    </ul>
                </PnPanel>
            </div>
        </template>

        <!-- Detalhe da entrega -->
        <Teleport to="body">
            <div v-if="detail || detailLoading" class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-4 backdrop-blur-[2px] sm:items-center" @click.self="closeDetail">
                <div class="max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-[28px] border border-[var(--border-strong)] bg-[var(--bg-surface)] p-6 shadow-2xl"
                    role="dialog" aria-modal="true" aria-labelledby="delivery-title" @keydown.esc="closeDetail">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 flex-wrap items-center gap-2">
                            <h2 id="delivery-title" class="text-base font-medium text-[var(--text-primary)]">Entrega de webhook</h2>
                            <span v-if="detail" class="pn-badge" :class="deliveryBadge(detail.status)">{{ deliveryLabel(detail.status) }}</span>
                        </div>
                        <button type="button" class="-mr-1 -mt-1 rounded-full p-2 text-[var(--text-secondary)] hover:bg-[var(--bg-muted)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)]" aria-label="Fechar" @click="closeDetail"><X class="h-5 w-5" /></button>
                    </div>
                    <p v-if="detailLoading" class="mt-4 text-sm text-[var(--text-tertiary)]">Carregando…</p>
                    <template v-else-if="detail">
                        <dl class="mt-4 grid grid-cols-[minmax(0,auto)_minmax(0,1fr)] gap-x-4 gap-y-2.5 rounded-2xl bg-[var(--bg-muted)]/50 p-4 text-sm">
                            <dt class="text-[var(--text-tertiary)]">Evento</dt><dd class="font-mono text-xs text-[var(--text-primary)]">{{ detail.event }}</dd>
                            <dt class="text-[var(--text-tertiary)]">ID do evento</dt><dd class="break-all font-mono text-xs text-[var(--text-primary)]">{{ detail.event_id }}</dd>
                            <dt class="text-[var(--text-tertiary)]">URL</dt><dd class="break-all text-xs text-[var(--text-primary)]">{{ detail.url }}</dd>
                            <dt class="text-[var(--text-tertiary)]">Status</dt><dd class="text-[var(--text-primary)]">{{ detail.status }} · HTTP {{ detail.last_status_code ?? '—' }} · {{ detail.attempt }} tentativa(s)</dd>
                            <dt class="text-[var(--text-tertiary)]">Tempo de resposta</dt><dd class="text-[var(--text-primary)]">{{ detail.duration_ms === null ? '—' : detail.duration_ms + ' ms' }}</dd>
                            <dt class="text-[var(--text-tertiary)]">Próxima tentativa</dt><dd class="text-[var(--text-primary)]">{{ fmtDate(detail.next_retry_at) }}</dd>
                        </dl>
                        <p class="mt-5 text-xs font-medium text-[var(--text-secondary)]">Payload (mascarado)</p>
                        <pre class="mt-1.5 max-h-[320px] overflow-auto rounded-2xl bg-[var(--ink-900)] p-4 text-xs leading-relaxed text-[#e8edf0]">{{ JSON.stringify(detail.payload, null, 2) }}</pre>
                        <p class="mt-4 text-xs font-medium text-[var(--text-secondary)]">Resposta do seu servidor</p>
                        <pre class="mt-1.5 max-h-[240px] overflow-auto whitespace-pre-wrap break-all rounded-2xl bg-[var(--bg-muted)] p-4 text-xs text-[var(--text-primary)]">{{ detail.response_body || '—' }}</pre>
                    </template>
                </div>
            </div>
        </Teleport>
    </div>
</template>

<style scoped>
.dev-code {
    border-radius: 6px;
    background: var(--bg-muted);
    padding: 1px 6px;
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: 0.85em;
    color: var(--text-primary);
    overflow-wrap: anywhere;
}
</style>
