<script setup>
import { computed, ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import Button from '@/components/ui/Button.vue';
import { Terminal, X } from 'lucide-vue-next';

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
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-semibold"><Terminal class="h-6 w-6" /> Desenvolvedores</h1>
                <p class="mt-1 max-w-2xl text-sm text-zinc-500">Consumo da API, erros, entregas de webhook e sandbox. Chaves e URLs de webhook ficam em <Link href="/aplicacoes-api" class="underline">Aplicações API</Link>.</p>
            </div>
            <div class="flex flex-wrap gap-2 text-sm">
                <a href="/docs/api-pagamentos" target="_blank" class="rounded-lg border border-zinc-200 px-3 py-1.5 dark:border-zinc-700">Documentação</a>
                <a href="/docs/api-pagamentos/openapi.json" target="_blank" class="rounded-lg border border-zinc-200 px-3 py-1.5 dark:border-zinc-700">OpenAPI</a>
                <a href="/docs/api-pagamentos/testar" target="_blank" class="rounded-lg border border-zinc-200 px-3 py-1.5 dark:border-zinc-700">Testar chamadas</a>
            </div>
        </div>

        <p v-if="!applications.length" class="rounded-2xl border border-dashed border-zinc-300 py-10 text-center text-sm text-zinc-500 dark:border-zinc-700">
            Nenhuma aplicação API ainda. <Link href="/aplicacoes-api" class="underline">Crie uma em Aplicações API</Link>.
        </p>

        <template v-else-if="o">
            <select v-if="applications.length > 1" :value="selected" class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800" @change="selectApp($event.target.value)">
                <option v-for="a in applications" :key="a.id" :value="a.id">{{ a.name }}</option>
            </select>

            <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
                <div v-for="k in [
                    ['Chamadas (7 dias)', o.live.total],
                    ['Últimas 24h', o.live.last_24h],
                    ['Taxa de erro', errorRate === null ? '—' : errorRate.toFixed(1) + '%'],
                    ['Tempo p95', o.live.p95_ms === null ? '—' : o.live.p95_ms + ' ms'],
                    ['Chamadas no sandbox', o.sandbox.total],
                ]" :key="k[0]" class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80">
                    <p class="text-xs text-zinc-500">{{ k[0] }}</p>
                    <p class="mt-1 text-xl font-semibold tabular-nums">{{ k[1] }}</p>
                </div>
            </div>

            <section class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900/80">
                <h2 class="font-semibold">Sandbox</h2>
                <p class="mt-1 text-sm text-zinc-500">
                    Crie uma chave de <strong>sandbox</strong> em Aplicações API (prefixo <code>gpk_test_</code>) e use os endpoints <code>/api/sandbox/v1</code>.
                    Nada ali gera cobrança, saldo ou saque real. Simule o resultado com <code>POST /api/sandbox/v1/simulate/payments/{id}/approve|expire|refund</code>
                    e <code>/simulate/withdrawals/{id}/process|pay|fail</code>. No cartão, o token <code>{{ sandbox_declined_token }}</code> simula recusa.
                </p>
                <p class="mt-2 text-xs text-zinc-500">Chaves de sandbox ativas: {{ o.sandbox.keys }} · transações de teste: {{ o.sandbox.transactions }}</p>
                <div class="mt-4 flex flex-wrap gap-1 border-b border-zinc-200 dark:border-zinc-700">
                    <button v-for="l in [['curl', 'cURL'], ['php', 'PHP'], ['js', 'JavaScript'], ['python', 'Python']]" :key="l[0]" type="button"
                        class="-mb-px border-b-2 px-3 py-1.5 text-sm" :class="lang === l[0] ? 'border-zinc-900 font-medium dark:border-white' : 'border-transparent text-zinc-500'" @click="lang = l[0]">{{ l[1] }}</button>
                </div>
                <pre class="mt-3 overflow-x-auto rounded-xl bg-zinc-950 p-4 text-xs leading-relaxed text-zinc-100"><code>{{ examples[lang] }}</code></pre>
            </section>

            <section class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900/80">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div>
                        <h2 class="font-semibold">Webhooks</h2>
                        <p class="text-xs text-zinc-500">Payload exibido com dados pessoais mascarados. Assinatura: <code>X-Webhook-Signature</code> = HMAC-SHA256 do corpo.</p>
                    </div>
                    <div class="flex items-center gap-2">
                        <select v-model="testEvent" class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                            <option v-for="e in test_events" :key="e" :value="e">{{ e }}</option>
                        </select>
                        <Button size="sm" :disabled="!o.has_webhook" @click="sendTest">Enviar evento de teste</Button>
                    </div>
                </div>
                <p v-if="testMsg" class="mt-2 text-xs text-zinc-600 dark:text-zinc-300">{{ testMsg }}</p>
                <p v-if="!o.has_webhook" class="mt-2 text-xs text-zinc-500">Cadastre uma URL de webhook para receber eventos.</p>
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[620px] text-sm">
                        <thead class="text-left text-xs text-zinc-500"><tr><th class="py-1">Evento</th><th>Ambiente</th><th>Status</th><th>HTTP</th><th>Tentativas</th><th>Tempo</th><th>Quando</th><th /></tr></thead>
                        <tbody>
                            <tr v-for="d in o.deliveries" :key="d.id" class="border-t border-zinc-100 dark:border-zinc-800">
                                <td class="py-1.5 font-mono text-xs">{{ d.event }}</td>
                                <td><span class="text-xs" :class="d.livemode ? '' : 'text-amber-600'">{{ d.livemode ? 'produção' : 'sandbox' }}</span></td>
                                <td>{{ d.status }}</td><td>{{ d.code ?? '—' }}</td><td>{{ d.attempt }}</td>
                                <td>{{ d.duration_ms === null ? '—' : d.duration_ms + ' ms' }}</td><td class="text-xs">{{ fmtDate(d.at) }}</td>
                                <td class="whitespace-nowrap text-right">
                                    <button type="button" class="text-xs underline" @click="openDelivery(d)">detalhes</button>
                                    <button v-if="d.status !== 'delivered'" type="button" class="ml-2 text-xs underline" @click="retry(d)">reenviar</button>
                                </td>
                            </tr>
                            <tr v-if="!o.deliveries.length"><td colspan="8" class="py-4 text-center text-zinc-500">Nenhuma entrega ainda.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <div class="grid gap-6 lg:grid-cols-2">
                <section class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900/80">
                    <h2 class="font-semibold">Rotas mais usadas (7 dias)</h2>
                    <table class="mt-3 w-full text-sm">
                        <thead class="text-left text-xs text-zinc-500"><tr><th class="py-1">Rota</th><th>Chamadas</th><th>Erros</th><th>Média</th></tr></thead>
                        <tbody>
                            <tr v-for="r in o.routes" :key="r.method + r.route" class="border-t border-zinc-100 dark:border-zinc-800">
                                <td class="py-1.5 font-mono text-xs">{{ r.method }} /{{ r.route }}</td><td>{{ r.total }}</td><td>{{ r.errors }}</td><td>{{ r.avg_ms }} ms</td>
                            </tr>
                            <tr v-if="!o.routes.length"><td colspan="4" class="py-4 text-center text-zinc-500">Sem chamadas no período.</td></tr>
                        </tbody>
                    </table>
                </section>
                <section class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900/80">
                    <h2 class="font-semibold">Erros recentes</h2>
                    <ul class="mt-3 divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                        <li v-for="e in o.errors" :key="e.id" class="py-2">
                            <p><span class="font-mono text-xs">{{ e.status }} {{ e.method }} /{{ e.route }}</span> <span v-if="e.environment === 'sandbox'" class="text-xs text-amber-600">sandbox</span></p>
                            <p class="text-xs text-zinc-500">{{ e.error || 'Sem mensagem' }} · {{ fmtDate(e.at) }}<template v-if="e.request_id"> · X-Request-Id {{ e.request_id }}</template></p>
                        </li>
                        <li v-if="!o.errors.length" class="py-4 text-center text-zinc-500">Nenhum erro registrado.</li>
                    </ul>
                </section>
            </div>
        </template>

        <div v-if="detail || detailLoading" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="detail = null">
            <div class="max-h-[85vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white p-5 dark:bg-zinc-900">
                <div class="flex items-start justify-between">
                    <h3 class="font-semibold">Entrega de webhook</h3>
                    <button type="button" aria-label="Fechar" @click="detail = null"><X class="h-5 w-5" /></button>
                </div>
                <p v-if="detailLoading" class="mt-3 text-sm text-zinc-500">Carregando…</p>
                <template v-else-if="detail">
                    <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                        <dt class="text-zinc-500">Evento</dt><dd class="font-mono text-xs">{{ detail.event }}</dd>
                        <dt class="text-zinc-500">ID do evento</dt><dd class="break-all font-mono text-xs">{{ detail.event_id }}</dd>
                        <dt class="text-zinc-500">URL</dt><dd class="break-all text-xs">{{ detail.url }}</dd>
                        <dt class="text-zinc-500">Status</dt><dd>{{ detail.status }} · HTTP {{ detail.last_status_code ?? '—' }} · {{ detail.attempt }} tentativa(s)</dd>
                        <dt class="text-zinc-500">Tempo de resposta</dt><dd>{{ detail.duration_ms === null ? '—' : detail.duration_ms + ' ms' }}</dd>
                        <dt class="text-zinc-500">Próxima tentativa</dt><dd>{{ fmtDate(detail.next_retry_at) }}</dd>
                    </dl>
                    <p class="mt-4 text-xs font-medium text-zinc-500">Payload (mascarado)</p>
                    <pre class="mt-1 overflow-x-auto rounded-xl bg-zinc-950 p-3 text-xs text-zinc-100">{{ JSON.stringify(detail.payload, null, 2) }}</pre>
                    <p class="mt-3 text-xs font-medium text-zinc-500">Resposta do seu servidor</p>
                    <pre class="mt-1 overflow-x-auto whitespace-pre-wrap rounded-xl bg-zinc-100 p-3 text-xs dark:bg-zinc-800">{{ detail.response_body || '—' }}</pre>
                </template>
            </div>
        </div>
    </div>
</template>
