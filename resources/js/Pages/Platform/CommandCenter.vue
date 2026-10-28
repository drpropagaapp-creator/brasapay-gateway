<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';
import { Gauge, RefreshCw, Search } from 'lucide-vue-next';

defineOptions({ layout: LayoutPlatform });

/**
 * Brasa Command Center — dados reais (blocos sem fonte aparecem como indisponíveis), central de alertas
 * com histórico e ações administrativas seguras com confirmação. Nada de dinheiro é movido daqui.
 */
const props = defineProps({
    executive: { type: Object, default: null },
    sellers: { type: Object, default: null },
    topSellers: { type: Array, default: null },
    withdrawals: { type: Object, default: null },
    treasury: { type: Object, default: null },
    risk: { type: Object, default: null },
    acquirers: { type: Array, default: null },
    infra: { type: Object, default: null },
    automations: { type: Object, default: null },
    alerts: { type: Array, default: () => [] },
    alert_center: { type: Object, default: () => ({ active: [], resolved: [] }) },
    failed_jobs: { type: Array, default: () => [] },
    failed_deliveries: { type: Array, default: () => [] },
    generated_at: { type: String, default: null },
});

const brl = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(v) || 0);
const pct = (v) => (v === null || v === undefined ? '—' : `${(v * 100).toFixed(1)}%`);
const dt = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR') : '—');
const SEV = {
    critical: 'border-red-300 bg-red-50 text-red-900 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200',
    high: 'border-orange-300 bg-orange-50 text-orange-900 dark:border-orange-900 dark:bg-orange-950/30 dark:text-orange-200',
    attention: 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200',
    info: 'border-zinc-200 bg-zinc-50 text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
};
const SEV_LABEL = { critical: 'Crítico', high: 'Alto', attention: 'Atenção', info: 'Informativo' };
const TABS = [['executiva', 'Visão executiva'], ['alertas', 'Alertas'], ['pagamentos', 'Pagamentos'], ['financeiro', 'Financeiro e risco'], ['sellers', 'Sellers'], ['infra', 'Infraestrutura'], ['automacoes', 'Automações']];
const tab = ref('executiva');
const activeCount = computed(() => props.alert_center.active.length);
// Alertas ao vivo (calculados agora) aparecem até a próxima avaliação gravar na central.
const liveAlerts = computed(() => (props.alert_center.active.length ? props.alert_center.active : props.alerts));

function refresh() {
    router.reload({ preserveScroll: true });
}
function ack(a) {
    const note = window.prompt('O que está sendo feito sobre este alerta?');
    if (note) router.post(`/plataforma/central/alertas/${a.id}/reconhecer`, { note }, { preserveScroll: true });
}
function retryJob(j) {
    if (window.confirm(`Reprocessar ${j.job}? Só jobs de notificação e rastreamento são permitidos.`)) router.post(`/plataforma/central/jobs/${j.uuid}/reprocessar`, {}, { preserveScroll: true });
}
function retryDelivery(d) {
    if (window.confirm(`Reenviar ${d.event} para ${d.host}?`)) router.post(`/plataforma/central/webhooks/${d.id}/reenviar`, {}, { preserveScroll: true });
}
const q = ref('');
const found = ref(null);
async function search() {
    if (q.value.trim().length < 2) return;
    found.value = (await window.axios.get('/plataforma/central/busca', { params: { q: q.value } })).data.results;
}
</script>

<template>
    <div class="mx-auto max-w-6xl space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Operação</p>
                <h1 class="mt-1 flex items-center gap-2 text-2xl font-bold text-zinc-900 dark:text-white"><Gauge class="h-6 w-6" /> Command Center</h1>
                <p class="mt-1 text-xs text-zinc-500">Atualizado {{ generated_at ? new Date(generated_at).toLocaleTimeString('pt-BR') : '—' }} (cache de 1 min; alertas reavaliados a cada 5 min)</p>
            </div>
            <div class="flex items-center gap-2">
                <form class="flex items-center gap-1" @submit.prevent="search">
                    <input v-model="q" placeholder="Pedido #id, seller e-mail ou id" class="w-60 rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-sm dark:border-zinc-700 dark:bg-zinc-800" />
                    <Button type="submit" size="sm" variant="ghost" aria-label="Buscar"><Search class="h-4 w-4" /></Button>
                </form>
                <Button type="button" variant="outline" class="gap-2" @click="refresh"><RefreshCw class="h-4 w-4" /> Atualizar</Button>
            </div>
        </div>
        <div v-if="found" class="rounded-xl border border-zinc-200 bg-white p-3 text-sm dark:border-zinc-700 dark:bg-zinc-900">
            <p v-if="!found.length" class="text-zinc-500">Nada encontrado.</p>
            <p v-for="(r, i) in found" :key="i"><span class="text-xs text-zinc-500">{{ r.type }}</span> <Link :href="r.href" class="underline">{{ r.label }}</Link></p>
        </div>

        <nav class="flex flex-wrap gap-1 border-b border-zinc-200 dark:border-zinc-700">
            <button v-for="t in TABS" :key="t[0]" type="button" class="-mb-px border-b-2 px-3 py-2 text-sm"
                :class="tab === t[0] ? 'border-zinc-900 font-medium dark:border-white' : 'border-transparent text-zinc-500'" @click="tab = t[0]">
                {{ t[1] }}<span v-if="t[0] === 'alertas' && activeCount" class="ml-1 rounded-full bg-red-600 px-1.5 text-[10px] text-white">{{ activeCount }}</span>
            </button>
        </nav>

        <!-- Visão executiva -->
        <template v-if="tab === 'executiva'">
            <section v-if="liveAlerts.length" class="space-y-2">
                <div v-for="(a, i) in liveAlerts.slice(0, 3)" :key="i" class="flex flex-wrap items-center justify-between gap-2 rounded-2xl border p-3 text-sm" :class="SEV[a.severity]">
                    <span><b>{{ SEV_LABEL[a.severity] }}</b> — {{ a.title }}</span>
                    <button type="button" class="text-xs font-medium underline" @click="tab = 'alertas'">Ver alertas</button>
                </div>
            </section>
            <p v-else class="rounded-2xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200">Nenhum alerta agora.</p>
            <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <template v-if="executive">
                    <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80"><p class="text-xs text-zinc-500">Aprovado hoje</p><p class="mt-1 text-xl font-semibold">{{ brl(executive.volume_today) }}</p><p class="text-xs text-zinc-500">{{ executive.orders_today }} pedidos</p></div>
                    <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80"><p class="text-xs text-zinc-500">Aprovado 7 dias</p><p class="mt-1 text-xl font-semibold">{{ brl(executive.volume_7d) }}</p><p class="text-xs text-zinc-500">{{ executive.orders_7d }} pedidos · {{ executive.pending_24h }} pendentes (24 h)</p></div>
                    <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80"><p class="text-xs text-zinc-500">Taxas / líquido hoje</p><p class="mt-1 text-xl font-semibold">{{ brl(executive.fees_today) }}</p><p class="text-xs text-zinc-500">líquido {{ brl(executive.net_today) }}</p></div>
                    <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80"><p class="text-xs text-zinc-500">Taxas / líquido 7 dias</p><p class="mt-1 text-xl font-semibold">{{ brl(executive.fees_7d) }}</p><p class="text-xs text-zinc-500">líquido {{ brl(executive.net_7d) }} · {{ executive.refunds_7d }} reembolsos</p></div>
                </template>
                <p v-else class="col-span-full text-sm text-zinc-500">Indicadores executivos indisponíveis.</p>
            </section>
            <section class="grid grid-cols-2 gap-3 lg:grid-cols-4 text-sm">
                <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80"><p class="text-xs text-zinc-500">Sellers ativos</p><p class="mt-1 text-xl font-semibold">{{ sellers?.active ?? '—' }}</p><p class="text-xs text-zinc-500">{{ sellers?.new_7d ?? '—' }} novos (7 d)</p></div>
                <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80"><p class="text-xs text-zinc-500">Saques aguardando</p><p class="mt-1 text-xl font-semibold">{{ withdrawals?.awaiting ?? '—' }}</p><p class="text-xs text-zinc-500">{{ withdrawals?.investigating ?? '—' }} em investigação</p></div>
                <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80"><p class="text-xs text-zinc-500">Divergências abertas</p><p class="mt-1 text-xl font-semibold">{{ treasury?.total ?? '—' }}</p><p class="text-xs text-zinc-500">{{ treasury?.critical ?? '—' }} críticas</p></div>
                <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80"><p class="text-xs text-zinc-500">Pedidos em MED</p><p class="mt-1 text-xl font-semibold">{{ executive?.disputed_open ?? '—' }}</p><p class="text-xs text-zinc-500">{{ risk?.high_open ?? '—' }} riscos altos</p></div>
            </section>
        </template>

        <!-- Alertas -->
        <template v-if="tab === 'alertas'">
            <p v-if="!liveAlerts.length" class="text-sm text-zinc-500">Nenhum alerta ativo.</p>
            <ul class="space-y-2">
                <li v-for="(a, i) in liveAlerts" :key="a.id ?? i" class="rounded-2xl border p-3 text-sm" :class="SEV[a.severity]">
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <div>
                            <p><b>{{ SEV_LABEL[a.severity] }}</b> · {{ a.area }} — {{ a.title }}</p>
                            <p v-if="a.hint" class="mt-0.5 text-xs opacity-90">{{ a.hint }}</p>
                            <p v-if="a.first_seen_at" class="mt-0.5 text-xs opacity-75">Desde {{ dt(a.first_seen_at) }}<template v-if="a.status === 'acknowledged'"> · reconhecido: {{ a.ack_note }}</template></p>
                        </div>
                        <div class="flex gap-2">
                            <Link v-if="a.href" :href="a.href" class="text-xs font-medium underline">Abrir</Link>
                            <button v-if="a.id && a.status === 'open'" type="button" class="text-xs font-medium underline" @click="ack(a)">Reconhecer</button>
                        </div>
                    </div>
                </li>
            </ul>
            <section v-if="alert_center.resolved.length" class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80">
                <h3 class="font-semibold">Resolvidos nos últimos 7 dias</h3>
                <ul class="mt-2 text-sm">
                    <li v-for="a in alert_center.resolved" :key="a.id" class="py-1 text-zinc-600 dark:text-zinc-300">{{ SEV_LABEL[a.severity] }} — {{ a.title }} <span class="text-xs text-zinc-500">({{ dt(a.first_seen_at) }} → {{ dt(a.resolved_at) }})</span></li>
                </ul>
            </section>
        </template>

        <!-- Pagamentos -->
        <section v-if="tab === 'pagamentos'" class="overflow-x-auto rounded-2xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900/80">
            <div class="flex items-center justify-between border-b border-zinc-100 px-4 py-3 dark:border-zinc-800">
                <h3 class="font-semibold">Adquirentes — última hora</h3>
                <Link href="/plataforma/ops/adquirentes" class="text-xs underline">Roteamento e desligamento</Link>
            </div>
            <p v-if="!acquirers || !acquirers.length" class="px-4 py-4 text-sm text-zinc-500">Sem tentativas na última hora.</p>
            <table v-else class="w-full min-w-[560px] text-sm">
                <thead class="text-left text-xs text-zinc-500"><tr><th class="px-4 py-2">Adquirente</th><th class="px-4 py-2">Método</th><th class="px-4 py-2">Tentativas</th><th class="px-4 py-2">Aprovação técnica</th><th class="px-4 py-2">Erro</th><th class="px-4 py-2">Timeout</th><th class="px-4 py-2">p95</th></tr></thead>
                <tbody>
                    <tr v-for="a in acquirers" :key="`${a.slug}-${a.method}`" class="border-t border-zinc-100 dark:border-zinc-800">
                        <td class="px-4 py-2">{{ a.slug }}</td><td class="px-4 py-2">{{ a.method }}</td><td class="px-4 py-2">{{ a.attempts }}</td>
                        <td class="px-4 py-2">{{ pct(a.success_rate) }}</td><td class="px-4 py-2">{{ pct(a.technical_error_rate) }}</td><td class="px-4 py-2">{{ pct(a.timeout_rate) }}</td><td class="px-4 py-2">{{ a.latency_p95_ms ?? '—' }} ms</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <!-- Financeiro e risco -->
        <div v-if="tab === 'financeiro'" class="grid gap-3 lg:grid-cols-2">
            <section class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80">
                <h3 class="font-semibold">Saques</h3>
                <dl v-if="withdrawals" class="mt-2 grid grid-cols-2 gap-2 text-sm">
                    <dt class="text-zinc-500">Aguardando</dt><dd class="text-right"><Link href="/plataforma/saques" class="hover:underline">{{ withdrawals.awaiting }}</Link></dd>
                    <dt class="text-zinc-500">Pagos (7 d)</dt><dd class="text-right">{{ withdrawals.paid_7d }} · {{ brl(withdrawals.paid_amount_7d) }}</dd>
                    <dt class="text-zinc-500">Em investigação</dt><dd class="text-right" :class="withdrawals.investigating ? 'text-orange-600' : ''">{{ withdrawals.investigating }}</dd>
                </dl>
                <p v-else class="mt-2 text-sm text-zinc-500">Indisponível.</p>
            </section>
            <section class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80">
                <h3 class="font-semibold">Tesouraria e risco</h3>
                <dl class="mt-2 grid grid-cols-2 gap-2 text-sm">
                    <dt class="text-zinc-500">Divergências críticas</dt><dd class="text-right" :class="treasury?.critical ? 'text-red-600' : ''"><Link href="/plataforma/tesouraria" class="hover:underline">{{ treasury?.critical ?? '—' }}</Link></dd>
                    <dt class="text-zinc-500">Divergências abertas</dt><dd class="text-right">{{ treasury?.total ?? '—' }}</dd>
                    <dt class="text-zinc-500">Última conciliação</dt><dd class="text-right">{{ dt(treasury?.last_run) }}</dd>
                    <dt class="text-zinc-500">Risco alto aberto</dt><dd class="text-right"><Link href="/plataforma/risco" class="hover:underline">{{ risk?.high_open ?? '—' }}</Link></dd>
                    <dt class="text-zinc-500">Risco médio aberto</dt><dd class="text-right">{{ risk?.medium_open ?? '—' }}</dd>
                    <dt class="text-zinc-500">Pedidos em MED</dt><dd class="text-right"><Link href="/plataforma/disputas" class="hover:underline">{{ executive?.disputed_open ?? '—' }}</Link></dd>
                </dl>
            </section>
        </div>

        <!-- Sellers -->
        <template v-if="tab === 'sellers'">
            <section class="grid grid-cols-2 gap-3 lg:grid-cols-4 text-sm">
                <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80"><p class="text-xs text-zinc-500">Ativos</p><p class="mt-1 text-xl font-semibold">{{ sellers?.active ?? '—' }}</p></div>
                <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80"><p class="text-xs text-zinc-500">Suspensos/bloqueados</p><p class="mt-1 text-xl font-semibold">{{ sellers?.suspended ?? '—' }}</p></div>
                <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80"><p class="text-xs text-zinc-500">KYC em análise</p><p class="mt-1 text-xl font-semibold"><Link href="/plataforma/verificacoes-kyc" class="hover:underline">{{ sellers?.kyc_review ?? '—' }}</Link></p></div>
                <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80"><p class="text-xs text-zinc-500">Novos (7 d)</p><p class="mt-1 text-xl font-semibold">{{ sellers?.new_7d ?? '—' }}</p></div>
            </section>
            <section class="overflow-x-auto rounded-2xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900/80">
                <h3 class="border-b border-zinc-100 px-4 py-3 font-semibold dark:border-zinc-800">Maiores volumes — 30 dias</h3>
                <table class="w-full min-w-[560px] text-sm">
                    <thead class="text-left text-xs text-zinc-500"><tr><th class="px-4 py-2">Seller</th><th class="px-4 py-2">Status</th><th class="px-4 py-2">Aprovado</th><th class="px-4 py-2">Vendas</th><th class="px-4 py-2">Reembolso + MED</th></tr></thead>
                    <tbody>
                        <tr v-for="s in topSellers || []" :key="s.tenant_id" class="border-t border-zinc-100 dark:border-zinc-800">
                            <td class="px-4 py-2"><Link :href="`/plataforma/usuarios/${s.tenant_id}`" class="hover:underline">{{ s.name || '#' + s.tenant_id }}</Link> <span class="text-xs text-zinc-500">{{ s.email }}</span></td>
                            <td class="px-4 py-2">{{ s.status }}</td><td class="px-4 py-2">{{ brl(s.volume_30d) }}</td><td class="px-4 py-2">{{ s.paid_30d }}</td>
                            <td class="px-4 py-2" :class="s.loss_rate > 0.05 ? 'text-orange-600' : ''">{{ pct(s.loss_rate) }}</td>
                        </tr>
                        <tr v-if="!topSellers?.length"><td colspan="5" class="px-4 py-4 text-zinc-500">Sem vendas no período.</td></tr>
                    </tbody>
                </table>
            </section>
        </template>

        <!-- Infraestrutura -->
        <template v-if="tab === 'infra'">
            <section class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80">
                <h3 class="font-semibold">Saúde técnica</h3>
                <dl v-if="infra" class="mt-2 grid grid-cols-2 gap-2 text-sm md:grid-cols-4">
                    <dt class="text-zinc-500">Agendador</dt><dd :class="infra.scheduler_minutes_ago === null || infra.scheduler_minutes_ago > 5 ? 'text-red-600' : ''">{{ infra.scheduler_minutes_ago === null ? 'sem sinal' : `há ${infra.scheduler_minutes_ago} min` }}</dd>
                    <dt class="text-zinc-500">Filas (heartbeat)</dt><dd>{{ infra.queue_minutes_ago === null ? 'sem sinal' : `há ${infra.queue_minutes_ago} min` }}</dd>
                    <dt class="text-zinc-500">Banco</dt><dd>{{ infra.db_ms }} ms</dd>
                    <dt class="text-zinc-500">Redis</dt><dd :class="infra.redis_ms === -1 ? 'text-red-600' : ''">{{ infra.redis_ms === null ? 'não usado' : infra.redis_ms === -1 ? 'sem resposta' : infra.redis_ms + ' ms' }}</dd>
                    <dt class="text-zinc-500">Disco</dt><dd :class="infra.disk_used_pct >= 0.85 ? 'text-orange-600' : ''">{{ pct(infra.disk_used_pct) }} usado · {{ infra.disk_free_gb ?? '—' }} GB livres</dd>
                    <dt class="text-zinc-500">API pública (1 h)</dt><dd>{{ infra.api_calls_1h ?? '—' }} chamadas · {{ infra.api_5xx_1h ?? '—' }} erros 5xx</dd>
                    <dt class="text-zinc-500">Jobs falhos (24 h)</dt><dd>{{ infra.failed_jobs_24h ?? '—' }}</dd>
                    <dt class="text-zinc-500">Servidor web</dt><dd>{{ infra.web_server }}</dd>
                </dl>
                <p v-else class="mt-2 text-sm text-zinc-500">Indisponível.</p>
                <div v-if="infra?.queues" class="mt-3 flex flex-wrap gap-2 text-xs">
                    <span v-for="(size, qn) in infra.queues" :key="qn" class="rounded-full border border-zinc-200 px-2.5 py-1 dark:border-zinc-700">{{ qn }}: {{ size ?? '?' }}</span>
                </div>
            </section>
            <section class="overflow-x-auto rounded-2xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900/80">
                <h3 class="border-b border-zinc-100 px-4 py-3 font-semibold dark:border-zinc-800">Jobs que falharam</h3>
                <p class="px-4 pt-2 text-xs text-zinc-500">Só jobs de notificação e rastreamento podem ser reprocessados aqui. Cobranças, saques e conciliações seguem nas telas próprias.</p>
                <table class="w-full min-w-[640px] text-sm">
                    <thead class="text-left text-xs text-zinc-500"><tr><th class="px-4 py-2">Job</th><th class="px-4 py-2">Fila</th><th class="px-4 py-2">Erro</th><th class="px-4 py-2">Quando</th><th /></tr></thead>
                    <tbody>
                        <tr v-for="j in failed_jobs" :key="j.uuid" class="border-t border-zinc-100 dark:border-zinc-800">
                            <td class="px-4 py-2 font-mono text-xs">{{ j.job }}</td><td class="px-4 py-2">{{ j.queue }}</td>
                            <td class="max-w-[280px] truncate px-4 py-2 text-xs" :title="j.error">{{ j.error }}</td><td class="px-4 py-2 text-xs">{{ j.at }}</td>
                            <td class="px-4 py-2 text-right"><button v-if="j.retryable" type="button" class="text-xs underline" @click="retryJob(j)">reprocessar</button></td>
                        </tr>
                        <tr v-if="!failed_jobs.length"><td colspan="5" class="px-4 py-4 text-zinc-500">Nenhum job falho.</td></tr>
                    </tbody>
                </table>
            </section>
        </template>

        <!-- Automações -->
        <template v-if="tab === 'automacoes'">
            <section class="grid grid-cols-2 gap-3 lg:grid-cols-4 text-sm">
                <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80"><p class="text-xs text-zinc-500">Recuperação (24 h)</p><p class="mt-1 text-xl font-semibold">{{ automations?.recovery_sent_24h ?? '—' }}</p><p class="text-xs text-zinc-500">{{ automations?.recovery_failed_24h ?? '—' }} falhas</p></div>
                <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80"><p class="text-xs text-zinc-500">Webhooks API (24 h)</p><p class="mt-1 text-xl font-semibold">{{ automations?.webhooks_delivered_24h ?? '—' }}</p><p class="text-xs text-zinc-500">{{ automations?.webhooks_failed_24h ?? '—' }} falharam</p></div>
                <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80"><p class="text-xs text-zinc-500">Testes A/B rodando</p><p class="mt-1 text-xl font-semibold">{{ automations?.experiments_running ?? '—' }}</p></div>
                <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80"><p class="text-xs text-zinc-500">Assistente IA (24 h)</p><p class="mt-1 text-xl font-semibold">{{ automations?.ai_answers_24h ?? '—' }}</p><p class="text-xs text-zinc-500">{{ automations?.ai_errors_1h ?? '—' }} falhas na última hora</p></div>
            </section>
            <section class="overflow-x-auto rounded-2xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900/80">
                <h3 class="border-b border-zinc-100 px-4 py-3 font-semibold dark:border-zinc-800">Webhooks da API que falharam (3 dias)</h3>
                <table class="w-full min-w-[560px] text-sm">
                    <thead class="text-left text-xs text-zinc-500"><tr><th class="px-4 py-2">Conta</th><th class="px-4 py-2">Evento</th><th class="px-4 py-2">Destino</th><th class="px-4 py-2">HTTP</th><th class="px-4 py-2">Tentativas</th><th /></tr></thead>
                    <tbody>
                        <tr v-for="d in failed_deliveries" :key="d.id" class="border-t border-zinc-100 dark:border-zinc-800">
                            <td class="px-4 py-2">#{{ d.tenant_id }}</td><td class="px-4 py-2 font-mono text-xs">{{ d.event }}</td><td class="px-4 py-2 text-xs">{{ d.host }}</td>
                            <td class="px-4 py-2">{{ d.code ?? '—' }}</td><td class="px-4 py-2">{{ d.attempt }}</td>
                            <td class="px-4 py-2 text-right"><button type="button" class="text-xs underline" @click="retryDelivery(d)">reenviar</button></td>
                        </tr>
                        <tr v-if="!failed_deliveries.length"><td colspan="6" class="px-4 py-4 text-zinc-500">Nenhuma entrega falhou.</td></tr>
                    </tbody>
                </table>
            </section>
        </template>
    </div>
</template>
