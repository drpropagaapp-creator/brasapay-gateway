<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import PnPageHeader from '@/components/paynext/PnPageHeader.vue';
import PnKpiCard from '@/components/paynext/PnKpiCard.vue';
import PnPanel from '@/components/paynext/PnPanel.vue';
import PnEmptyState from '@/components/paynext/PnEmptyState.vue';
import {
    Activity, ArrowUpRight, Banknote, BellOff, Bot, CircleCheck, Clock, Coins, FlaskConical, Gauge, Landmark, Mail,
    RefreshCw, RotateCcw, Scale, Search, Send, ShieldAlert, Store, TrendingUp, Users, Wallet, Webhook,
} from 'lucide-vue-next';

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

/* Severidade: badge, faixa lateral e fundo do cartão (crítico vermelho, alto laranja, atenção âmbar, informativo neutro). */
const SEV_BADGE = {
    critical: 'pn-badge pn-badge--error',
    high: 'pn-badge bg-orange-500/15 text-orange-700 dark:text-orange-300',
    attention: 'pn-badge pn-badge--warning',
    info: 'pn-badge pn-badge--neutral',
};
const SEV_STRIPE = { critical: 'bg-red-500', high: 'bg-orange-500', attention: 'bg-amber-400', info: 'bg-[var(--ink-300)]' };
const SEV_TINT = {
    critical: 'border-red-300/70 bg-red-50/80 dark:border-red-900/70 dark:bg-red-950/30',
    high: 'border-orange-300/70 bg-orange-50/80 dark:border-orange-900/70 dark:bg-orange-950/30',
    attention: 'border-amber-300/70 bg-amber-50/70 dark:border-amber-900/70 dark:bg-amber-950/25',
    info: 'border-[var(--border-strong)] bg-[var(--glass-bg)]',
};
const SEV_LABEL = { critical: 'Crítico', high: 'Alto', attention: 'Atenção', info: 'Informativo' };
const TABS = [['executiva', 'Visão executiva'], ['alertas', 'Alertas'], ['pagamentos', 'Pagamentos'], ['financeiro', 'Financeiro e risco'], ['sellers', 'Sellers'], ['infra', 'Infraestrutura'], ['automacoes', 'Automações']];
const tab = ref('executiva');
const activeCount = computed(() => props.alert_center.active.length);
// Alertas ao vivo (calculados agora) aparecem até a próxima avaliação gravar na central.
const liveAlerts = computed(() => (props.alert_center.active.length ? props.alert_center.active : props.alerts));

/* Classes de apresentação reaproveitadas nas tabelas. */
const THEAD = 'text-left text-[11px] uppercase tracking-[0.06em] text-[var(--text-tertiary)]';
const TH = 'whitespace-nowrap px-3 py-2.5 font-normal first:pl-0 last:pr-0';
const TR = 'border-t border-[var(--border-strong)] transition-colors hover:bg-[var(--bg-muted)]/40';
const TD = 'px-3 py-2.5 first:pl-0 last:pr-0';
const LINK = 'rounded-sm underline-offset-2 hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--focus-ring)]';

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
        <PnPageHeader eyebrow="Operação" title="Command Center" :icon="Gauge"
            description="Pagamentos, saldo, risco e infraestrutura num só lugar. Nada de dinheiro é movido daqui: as ações só reconhecem alertas e reenviam notificações.">
            <template #actions>
                <button type="button" class="pn-btn pn-btn--secondary pn-btn--sm !w-auto" @click="refresh"><RefreshCw class="h-4 w-4" aria-hidden="true" /> Atualizar</button>
            </template>
        </PnPageHeader>

        <!-- Barra: atualização + busca -->
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="inline-flex items-center gap-1.5 text-xs text-[var(--text-tertiary)]">
                <Clock class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                Atualizado {{ generated_at ? new Date(generated_at).toLocaleTimeString('pt-BR') : '—' }} (cache de 1 min; alertas reavaliados a cada 5 min)
            </p>
            <form class="relative w-full sm:w-80" role="search" @submit.prevent="search">
                <label for="cc-search" class="sr-only">Buscar pedido ou seller</label>
                <Search class="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--text-tertiary)]" aria-hidden="true" />
                <input id="cc-search" v-model="q" type="search" placeholder="Pedido #id, seller e-mail ou id"
                    class="h-10 w-full rounded-full border border-[var(--border-strong)] bg-[var(--glass-bg)] pl-10 pr-20 text-sm text-[var(--text-primary)] outline-none placeholder:text-[var(--text-tertiary)] focus:ring-4 focus:ring-[var(--focus-ring)]" />
                <button type="submit" class="absolute right-1 top-1/2 h-8 -translate-y-1/2 rounded-full px-3 text-xs font-medium text-[var(--text-secondary)] transition hover:bg-[var(--bg-muted)] hover:text-[var(--text-primary)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)]" aria-label="Buscar">Buscar</button>
            </form>
        </div>

        <PnPanel v-if="found" title="Resultado da busca" :sub="found.length ? `${found.length} item(ns) encontrado(s)` : ''" :pad="20">
            <p v-if="!found.length" class="text-sm text-[var(--text-tertiary)]">Nada encontrado.</p>
            <ul v-else class="divide-y divide-[var(--border-strong)]">
                <li v-for="(r, i) in found" :key="i" class="flex min-w-0 items-center gap-2 py-2 text-sm">
                    <span class="pn-badge pn-badge--neutral pn-badge--nodot shrink-0">{{ r.type }}</span>
                    <Link :href="r.href" :class="LINK" class="min-w-0 truncate text-[var(--text-primary)]">{{ r.label }}</Link>
                    <ArrowUpRight class="h-3.5 w-3.5 shrink-0 text-[var(--text-tertiary)]" aria-hidden="true" />
                </li>
            </ul>
        </PnPanel>

        <!-- Abas -->
        <div class="max-w-full">
            <nav class="pn-tabs" role="tablist" aria-label="Seções do Command Center">
                <button v-for="t in TABS" :key="t[0]" type="button" role="tab" :aria-selected="tab === t[0]"
                    class="pn-tab pn-keep-shape inline-flex items-center focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)]"
                    :class="tab === t[0] ? 'pn-tab--active' : ''" @click="tab = t[0]">
                    {{ t[1] }}<span v-if="t[0] === 'alertas' && activeCount" class="ml-1.5 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-red-600 px-1.5 text-[10px] font-medium text-white" :aria-label="`${activeCount} alertas ativos`">{{ activeCount }}</span>
                </button>
            </nav>
        </div>

        <!-- Visão executiva -->
        <template v-if="tab === 'executiva'">
            <section v-if="liveAlerts.length" class="space-y-2" aria-label="Alertas mais recentes">
                <div v-for="(a, i) in liveAlerts.slice(0, 3)" :key="i" class="relative flex flex-wrap items-center justify-between gap-3 overflow-hidden rounded-[22px] border py-3 pl-5 pr-4 text-sm" :class="SEV_TINT[a.severity]">
                    <span class="absolute inset-y-0 left-0 w-1" :class="SEV_STRIPE[a.severity]" aria-hidden="true" />
                    <span class="flex min-w-0 flex-wrap items-center gap-2">
                        <span :class="SEV_BADGE[a.severity]">{{ SEV_LABEL[a.severity] }}</span>
                        <span class="text-[var(--text-primary)]">{{ a.title }}</span>
                    </span>
                    <button type="button" class="pn-btn pn-btn--ghost pn-btn--sm !w-auto" @click="tab = 'alertas'">Ver alertas</button>
                </div>
            </section>
            <p v-else class="flex items-center gap-2 rounded-[22px] border border-emerald-200 bg-emerald-50/80 px-4 py-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200">
                <CircleCheck class="h-4 w-4 shrink-0" aria-hidden="true" /> Nenhum alerta agora.
            </p>

            <section v-if="executive" class="cc-kpis grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="Indicadores executivos">
                <PnKpiCard label="Aprovado hoje" :value="brl(executive.volume_today)" :icon="TrendingUp" :caption="`${executive.orders_today} pedidos`" :index="0" />
                <PnKpiCard label="Aprovado 7 dias" :value="brl(executive.volume_7d)" :icon="Activity" :caption="`${executive.orders_7d} pedidos · ${executive.pending_24h} pendentes (24 h)`" :index="1" />
                <PnKpiCard label="Taxas / líquido hoje" :value="brl(executive.fees_today)" :icon="Coins" :caption="`líquido ${brl(executive.net_today)}`" :index="2" />
                <PnKpiCard label="Taxas / líquido 7 dias" :value="brl(executive.fees_7d)" :icon="Wallet" :caption="`líquido ${brl(executive.net_7d)} · ${executive.refunds_7d} reembolsos`" :index="3" />
            </section>
            <PnEmptyState v-else title="Indicadores executivos indisponíveis" description="Sem dados desta fonte no momento. Use Atualizar para tentar de novo." :icon="TrendingUp" />

            <section class="cc-kpis grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="Filas da operação">
                <PnKpiCard label="Sellers ativos" :value="sellers?.active ?? '—'" :icon="Store" :caption="`${sellers?.new_7d ?? '—'} novos (7 d)`" :index="4" />
                <PnKpiCard label="Saques aguardando" :value="withdrawals?.awaiting ?? '—'" :icon="Banknote" :caption="`${withdrawals?.investigating ?? '—'} em investigação`" :index="5" />
                <PnKpiCard label="Divergências abertas" :value="treasury?.total ?? '—'" :icon="Scale" :caption="`${treasury?.critical ?? '—'} críticas`" :index="6" />
                <PnKpiCard label="Pedidos em MED" :value="executive?.disputed_open ?? '—'" :icon="ShieldAlert" :caption="`${risk?.high_open ?? '—'} riscos altos`" :index="7" />
            </section>
        </template>

        <!-- Alertas -->
        <template v-if="tab === 'alertas'">
            <PnEmptyState v-if="!liveAlerts.length" title="Nenhum alerta ativo" description="Quando algo sair do normal, o alerta aparece aqui com a severidade e o próximo passo." :icon="BellOff" />
            <ul v-else class="space-y-2">
                <li v-for="(a, i) in liveAlerts" :key="a.id ?? i" class="relative overflow-hidden rounded-[22px] border py-3.5 pl-5 pr-4 text-sm" :class="SEV_TINT[a.severity]">
                    <span class="absolute inset-y-0 left-0 w-1" :class="SEV_STRIPE[a.severity]" aria-hidden="true" />
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span :class="SEV_BADGE[a.severity]">{{ SEV_LABEL[a.severity] }}</span>
                                <span class="text-xs text-[var(--text-tertiary)]">{{ a.area }}</span>
                                <span v-if="a.status === 'acknowledged'" class="pn-badge pn-badge--info">Reconhecido</span>
                            </div>
                            <p class="mt-1.5 font-medium text-[var(--text-primary)]">{{ a.title }}</p>
                            <p v-if="a.hint" class="mt-0.5 text-xs text-[var(--text-secondary)]">{{ a.hint }}</p>
                            <p v-if="a.first_seen_at" class="mt-1 text-xs text-[var(--text-tertiary)]">Desde {{ dt(a.first_seen_at) }}<template v-if="a.status === 'acknowledged'"> · reconhecido: {{ a.ack_note }}</template></p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Link v-if="a.href" :href="a.href" class="pn-btn pn-btn--secondary pn-btn--sm !w-auto">Abrir <ArrowUpRight class="h-3.5 w-3.5" aria-hidden="true" /></Link>
                            <button v-if="a.id && a.status === 'open'" type="button" class="pn-btn pn-btn--ghost pn-btn--sm !w-auto" @click="ack(a)">Reconhecer</button>
                        </div>
                    </div>
                </li>
            </ul>
            <PnPanel v-if="alert_center.resolved.length" title="Resolvidos nos últimos 7 dias" :sub="`${alert_center.resolved.length} alerta(s)`">
                <ul class="divide-y divide-[var(--border-strong)] text-sm">
                    <li v-for="a in alert_center.resolved" :key="a.id" class="flex flex-wrap items-center gap-x-2 gap-y-1 py-2.5">
                        <span :class="SEV_BADGE[a.severity]">{{ SEV_LABEL[a.severity] }}</span>
                        <span class="min-w-0 text-[var(--text-secondary)]">{{ a.title }}</span>
                        <span class="text-xs text-[var(--text-tertiary)]">({{ dt(a.first_seen_at) }} → {{ dt(a.resolved_at) }})</span>
                    </li>
                </ul>
            </PnPanel>
        </template>

        <!-- Pagamentos -->
        <PnPanel v-if="tab === 'pagamentos'" title="Adquirentes — última hora" sub="Tentativas reais de cobrança por adquirente e método.">
            <template #right>
                <Link href="/plataforma/ops/adquirentes" class="pn-btn pn-btn--ghost pn-btn--sm !w-auto shrink-0">Roteamento e desligamento <ArrowUpRight class="h-3.5 w-3.5" aria-hidden="true" /></Link>
            </template>
            <p v-if="!acquirers || !acquirers.length" class="py-4 text-sm text-[var(--text-tertiary)]">Sem tentativas na última hora.</p>
            <div v-else class="relative overflow-x-auto">
                <table class="w-full min-w-[560px] text-sm">
                    <thead :class="THEAD"><tr><th :class="TH">Adquirente</th><th :class="TH">Método</th><th :class="TH">Tentativas</th><th :class="TH">Aprovação técnica</th><th :class="TH">Erro</th><th :class="TH">Timeout</th><th :class="TH">p95</th></tr></thead>
                    <tbody class="text-[var(--text-primary)]">
                        <tr v-for="a in acquirers" :key="`${a.slug}-${a.method}`" :class="TR">
                            <td :class="TD" class="font-medium">{{ a.slug }}</td><td :class="TD">{{ a.method }}</td><td :class="TD" class="tabular-nums">{{ a.attempts }}</td>
                            <td :class="TD" class="tabular-nums">{{ pct(a.success_rate) }}</td><td :class="TD" class="tabular-nums">{{ pct(a.technical_error_rate) }}</td><td :class="TD" class="tabular-nums">{{ pct(a.timeout_rate) }}</td><td :class="TD" class="tabular-nums">{{ a.latency_p95_ms ?? '—' }} ms</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </PnPanel>

        <!-- Financeiro e risco -->
        <div v-if="tab === 'financeiro'" class="grid gap-3 lg:grid-cols-2">
            <PnPanel title="Saques" sub="Fila e pagamentos dos últimos 7 dias.">
                <template #right><Banknote class="h-5 w-5 shrink-0 text-[var(--text-tertiary)]" aria-hidden="true" /></template>
                <dl v-if="withdrawals" class="divide-y divide-[var(--border-strong)] text-sm">
                    <div class="flex items-center justify-between gap-3 py-2.5"><dt class="text-[var(--text-secondary)]">Aguardando</dt><dd class="font-medium tabular-nums"><Link href="/plataforma/saques" :class="LINK">{{ withdrawals.awaiting }}</Link></dd></div>
                    <div class="flex items-center justify-between gap-3 py-2.5"><dt class="text-[var(--text-secondary)]">Pagos (7 d)</dt><dd class="text-right tabular-nums">{{ withdrawals.paid_7d }} · {{ brl(withdrawals.paid_amount_7d) }}</dd></div>
                    <div class="flex items-center justify-between gap-3 py-2.5"><dt class="text-[var(--text-secondary)]">Em investigação</dt><dd class="font-medium tabular-nums" :class="withdrawals.investigating ? 'text-orange-600 dark:text-orange-400' : ''">{{ withdrawals.investigating }}</dd></div>
                </dl>
                <p v-else class="text-sm text-[var(--text-tertiary)]">Indisponível.</p>
            </PnPanel>
            <PnPanel title="Tesouraria e risco" sub="Conciliação, BrasaShield e MED.">
                <template #right><Landmark class="h-5 w-5 shrink-0 text-[var(--text-tertiary)]" aria-hidden="true" /></template>
                <dl class="divide-y divide-[var(--border-strong)] text-sm">
                    <div class="flex items-center justify-between gap-3 py-2.5"><dt class="text-[var(--text-secondary)]">Divergências críticas</dt><dd class="font-medium tabular-nums" :class="treasury?.critical ? 'text-red-600 dark:text-red-400' : ''"><Link href="/plataforma/tesouraria" :class="LINK">{{ treasury?.critical ?? '—' }}</Link></dd></div>
                    <div class="flex items-center justify-between gap-3 py-2.5"><dt class="text-[var(--text-secondary)]">Divergências abertas</dt><dd class="tabular-nums">{{ treasury?.total ?? '—' }}</dd></div>
                    <div class="flex items-center justify-between gap-3 py-2.5"><dt class="text-[var(--text-secondary)]">Última conciliação</dt><dd class="text-right tabular-nums">{{ dt(treasury?.last_run) }}</dd></div>
                    <div class="flex items-center justify-between gap-3 py-2.5"><dt class="text-[var(--text-secondary)]">Risco alto aberto</dt><dd class="font-medium tabular-nums"><Link href="/plataforma/risco" :class="LINK">{{ risk?.high_open ?? '—' }}</Link></dd></div>
                    <div class="flex items-center justify-between gap-3 py-2.5"><dt class="text-[var(--text-secondary)]">Risco médio aberto</dt><dd class="tabular-nums">{{ risk?.medium_open ?? '—' }}</dd></div>
                    <div class="flex items-center justify-between gap-3 py-2.5"><dt class="text-[var(--text-secondary)]">Pedidos em MED</dt><dd class="font-medium tabular-nums"><Link href="/plataforma/disputas" :class="LINK">{{ executive?.disputed_open ?? '—' }}</Link></dd></div>
                </dl>
            </PnPanel>
        </div>

        <!-- Sellers -->
        <template v-if="tab === 'sellers'">
            <section class="cc-kpis grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="Sellers">
                <PnKpiCard label="Ativos" :value="sellers?.active ?? '—'" :icon="Store" :index="0" />
                <PnKpiCard label="Suspensos/bloqueados" :value="sellers?.suspended ?? '—'" :icon="ShieldAlert" :index="1" />
                <PnKpiCard label="KYC em análise" :value="sellers?.kyc_review ?? '—'" :icon="Users" :index="2">
                    <template #foot><Link href="/plataforma/verificacoes-kyc" :class="LINK" class="inline-flex items-center gap-1 text-[var(--text-secondary)]">Abrir verificações <ArrowUpRight class="h-3 w-3" aria-hidden="true" /></Link></template>
                </PnKpiCard>
                <PnKpiCard label="Novos (7 d)" :value="sellers?.new_7d ?? '—'" :icon="TrendingUp" :index="3" />
            </section>
            <PnPanel title="Maiores volumes — 30 dias" sub="Reembolso + MED acima de 5% aparece em laranja.">
                <div class="relative overflow-x-auto">
                    <table class="w-full min-w-[560px] text-sm">
                        <thead :class="THEAD"><tr><th :class="TH">Seller</th><th :class="TH">Status</th><th :class="TH">Aprovado</th><th :class="TH">Vendas</th><th :class="TH">Reembolso + MED</th></tr></thead>
                        <tbody class="text-[var(--text-primary)]">
                            <tr v-for="s in topSellers || []" :key="s.tenant_id" :class="TR">
                                <td :class="TD"><Link :href="`/plataforma/usuarios/${s.tenant_id}`" :class="LINK" class="font-medium">{{ s.name || '#' + s.tenant_id }}</Link> <span class="block text-xs text-[var(--text-tertiary)]">{{ s.email }}</span></td>
                                <td :class="TD"><span class="pn-badge pn-badge--neutral pn-badge--nodot">{{ s.status }}</span></td><td :class="TD" class="tabular-nums">{{ brl(s.volume_30d) }}</td><td :class="TD" class="tabular-nums">{{ s.paid_30d }}</td>
                                <td class="tabular-nums" :class="[TD, s.loss_rate > 0.05 ? 'font-medium text-orange-600 dark:text-orange-400' : '']">{{ pct(s.loss_rate) }}</td>
                            </tr>
                            <tr v-if="!topSellers?.length"><td colspan="5" class="py-6 text-center text-[var(--text-tertiary)]">Sem vendas no período.</td></tr>
                        </tbody>
                    </table>
                </div>
            </PnPanel>
        </template>

        <!-- Infraestrutura -->
        <template v-if="tab === 'infra'">
            <PnPanel title="Saúde técnica" sub="Vermelho: agendador sem sinal há mais de 5 min ou Redis sem resposta. Laranja: disco acima de 85%.">
                <dl v-if="infra" class="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-2xl bg-[var(--bg-muted)]/60 p-3"><dt class="text-[11px] text-[var(--text-tertiary)]">Agendador</dt><dd class="mt-0.5 font-medium" :class="infra.scheduler_minutes_ago === null || infra.scheduler_minutes_ago > 5 ? 'text-red-600 dark:text-red-400' : 'text-[var(--text-primary)]'">{{ infra.scheduler_minutes_ago === null ? 'sem sinal' : `há ${infra.scheduler_minutes_ago} min` }}</dd></div>
                    <div class="rounded-2xl bg-[var(--bg-muted)]/60 p-3"><dt class="text-[11px] text-[var(--text-tertiary)]">Filas (heartbeat)</dt><dd class="mt-0.5 font-medium text-[var(--text-primary)]">{{ infra.queue_minutes_ago === null ? 'sem sinal' : `há ${infra.queue_minutes_ago} min` }}</dd></div>
                    <div class="rounded-2xl bg-[var(--bg-muted)]/60 p-3"><dt class="text-[11px] text-[var(--text-tertiary)]">Banco</dt><dd class="mt-0.5 font-medium tabular-nums text-[var(--text-primary)]">{{ infra.db_ms }} ms</dd></div>
                    <div class="rounded-2xl bg-[var(--bg-muted)]/60 p-3"><dt class="text-[11px] text-[var(--text-tertiary)]">Redis</dt><dd class="mt-0.5 font-medium tabular-nums" :class="infra.redis_ms === -1 ? 'text-red-600 dark:text-red-400' : 'text-[var(--text-primary)]'">{{ infra.redis_ms === null ? 'não usado' : infra.redis_ms === -1 ? 'sem resposta' : infra.redis_ms + ' ms' }}</dd></div>
                    <div class="rounded-2xl bg-[var(--bg-muted)]/60 p-3"><dt class="text-[11px] text-[var(--text-tertiary)]">Disco</dt><dd class="mt-0.5 font-medium tabular-nums" :class="infra.disk_used_pct >= 0.85 ? 'text-orange-600 dark:text-orange-400' : 'text-[var(--text-primary)]'">{{ pct(infra.disk_used_pct) }} usado · {{ infra.disk_free_gb ?? '—' }} GB livres</dd></div>
                    <div class="rounded-2xl bg-[var(--bg-muted)]/60 p-3"><dt class="text-[11px] text-[var(--text-tertiary)]">API pública (1 h)</dt><dd class="mt-0.5 font-medium tabular-nums text-[var(--text-primary)]">{{ infra.api_calls_1h ?? '—' }} chamadas · {{ infra.api_5xx_1h ?? '—' }} erros 5xx</dd></div>
                    <div class="rounded-2xl bg-[var(--bg-muted)]/60 p-3"><dt class="text-[11px] text-[var(--text-tertiary)]">Jobs falhos (24 h)</dt><dd class="mt-0.5 font-medium tabular-nums text-[var(--text-primary)]">{{ infra.failed_jobs_24h ?? '—' }}</dd></div>
                    <div class="rounded-2xl bg-[var(--bg-muted)]/60 p-3"><dt class="text-[11px] text-[var(--text-tertiary)]">Servidor web</dt><dd class="mt-0.5 font-medium text-[var(--text-primary)]">{{ infra.web_server }}</dd></div>
                </dl>
                <p v-else class="text-sm text-[var(--text-tertiary)]">Indisponível.</p>
                <div v-if="infra?.queues" class="mt-4">
                    <p class="mb-2 text-xs text-[var(--text-secondary)]">Tamanho das filas</p>
                    <div class="flex flex-wrap gap-2 text-xs">
                        <span v-for="(size, qn) in infra.queues" :key="qn" class="inline-flex items-center gap-1.5 rounded-full border border-[var(--border-strong)] px-2.5 py-1 text-[var(--text-secondary)]">{{ qn }} <b class="font-medium tabular-nums text-[var(--text-primary)]">{{ size ?? '?' }}</b></span>
                    </div>
                </div>
            </PnPanel>
            <PnPanel title="Jobs que falharam" sub="Só jobs de notificação e rastreamento podem ser reprocessados aqui. Cobranças, saques e conciliações seguem nas telas próprias.">
                <div class="relative overflow-x-auto">
                    <table class="w-full min-w-[640px] text-sm">
                        <thead :class="THEAD"><tr><th :class="TH">Job</th><th :class="TH">Fila</th><th :class="TH">Erro</th><th :class="TH">Quando</th><th :class="TH"><span class="sr-only">Ações</span></th></tr></thead>
                        <tbody class="text-[var(--text-primary)]">
                            <tr v-for="j in failed_jobs" :key="j.uuid" :class="TR">
                                <td :class="TD" class="font-mono text-xs">{{ j.job }}</td><td :class="TD">{{ j.queue }}</td>
                                <td :class="TD" class="max-w-[280px] truncate text-xs text-red-700 dark:text-red-300" :title="j.error">{{ j.error }}</td><td :class="TD" class="whitespace-nowrap text-xs text-[var(--text-secondary)]">{{ j.at }}</td>
                                <td :class="TD" class="text-right"><button v-if="j.retryable" type="button" class="pn-btn pn-btn--ghost pn-btn--sm !w-auto" @click="retryJob(j)"><RotateCcw class="h-3.5 w-3.5" aria-hidden="true" /> Reprocessar</button></td>
                            </tr>
                            <tr v-if="!failed_jobs.length"><td colspan="5" class="py-6 text-center text-[var(--text-tertiary)]">Nenhum job falho.</td></tr>
                        </tbody>
                    </table>
                </div>
            </PnPanel>
        </template>

        <!-- Automações -->
        <template v-if="tab === 'automacoes'">
            <section class="cc-kpis grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="Automações">
                <PnKpiCard label="Recuperação (24 h)" :value="automations?.recovery_sent_24h ?? '—'" :icon="Mail" :caption="`${automations?.recovery_failed_24h ?? '—'} falhas`" :index="0" />
                <PnKpiCard label="Webhooks API (24 h)" :value="automations?.webhooks_delivered_24h ?? '—'" :icon="Webhook" :caption="`${automations?.webhooks_failed_24h ?? '—'} falharam`" :index="1" />
                <PnKpiCard label="Testes A/B rodando" :value="automations?.experiments_running ?? '—'" :icon="FlaskConical" :index="2" />
                <PnKpiCard label="Assistente IA (24 h)" :value="automations?.ai_answers_24h ?? '—'" :icon="Bot" :caption="`${automations?.ai_errors_1h ?? '—'} falhas na última hora`" :index="3" />
            </section>
            <PnPanel title="Webhooks da API que falharam (3 dias)" sub="Reenviar só repete a notificação para o destino do seller.">
                <div class="relative overflow-x-auto">
                    <table class="w-full min-w-[560px] text-sm">
                        <thead :class="THEAD"><tr><th :class="TH">Conta</th><th :class="TH">Evento</th><th :class="TH">Destino</th><th :class="TH">HTTP</th><th :class="TH">Tentativas</th><th :class="TH"><span class="sr-only">Ações</span></th></tr></thead>
                        <tbody class="text-[var(--text-primary)]">
                            <tr v-for="d in failed_deliveries" :key="d.id" :class="TR">
                                <td :class="TD" class="tabular-nums">#{{ d.tenant_id }}</td><td :class="TD" class="font-mono text-xs">{{ d.event }}</td><td :class="TD" class="text-xs text-[var(--text-secondary)]">{{ d.host }}</td>
                                <td :class="TD"><span class="pn-badge pn-badge--error pn-badge--nodot tabular-nums">{{ d.code ?? '—' }}</span></td><td :class="TD" class="tabular-nums">{{ d.attempt }}</td>
                                <td :class="TD" class="text-right"><button type="button" class="pn-btn pn-btn--ghost pn-btn--sm !w-auto" @click="retryDelivery(d)"><Send class="h-3.5 w-3.5" aria-hidden="true" /> Reenviar</button></td>
                            </tr>
                            <tr v-if="!failed_deliveries.length"><td colspan="6" class="py-6 text-center text-[var(--text-tertiary)]">Nenhuma entrega falhou.</td></tr>
                        </tbody>
                    </table>
                </div>
            </PnPanel>
        </template>
    </div>
</template>

<style scoped>
/* Valores em reais formatados ocupam mais espaço: valor do KPI um pouco menor que o padrão. */
.cc-kpis :deep(.pn-kpi) {
    container-type: inline-size;
}
.cc-kpis :deep(.pn-kpi__value) {
    /* Tamanho acompanha a largura do card; só quebra no espaço depois de "R$", nunca no meio do número. */
    font-size: clamp(18px, 9.5cqi, 32px);
    line-height: 1.2;
    overflow-wrap: normal;
}
</style>
