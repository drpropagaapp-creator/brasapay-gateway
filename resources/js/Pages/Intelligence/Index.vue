<script setup>
import { reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import PnPageHeader from '@/components/paynext/PnPageHeader.vue';
import PnKpiCard from '@/components/paynext/PnKpiCard.vue';
import PnPanel from '@/components/paynext/PnPanel.vue';
import PnMeter from '@/components/paynext/PnMeter.vue';
import {
    Lightbulb, Trash2, Wallet, ShoppingBag, UserPlus, TrendingUp, Target, OctagonAlert, TriangleAlert, Info, ArrowUpRight,
    Megaphone, Plus, Lock, CalendarDays,
} from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

/**
 * Brasa Intelligence — receita só de pagamentos confirmados; ROAS/CAC só onde há custo informado.
 */
const props = defineProps({
    days: { type: Number, required: true },
    periods: { type: Array, default: () => [7, 30, 90] },
    report: { type: Object, required: true },
    can_edit_spend: { type: Boolean, default: false },
    spends: { type: Array, default: () => [] },
});

const brl = (cents) => (cents === null || cents === undefined ? '—' : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(cents / 100));
const pct = (v) => (v === null || v === undefined ? '—' : `${(v * 100).toFixed(1)}%`);
const roas = (v) => (v === null || v === undefined ? '—' : `${String(v).replace('.', ',')}x`);
const num = (v) => new Intl.NumberFormat('pt-BR').format(Number(v) || 0);
const METHOD = { pix: 'Pix', card: 'Cartão', credit_card: 'Cartão', boleto: 'Boleto', pix_auto: 'Pix Automático', paypal: 'PayPal', open_finance: 'Open Finance', outro: 'Outro' };
const LEVEL = {
    danger: { box: 'border-[var(--status-error-fg)]/30 bg-[var(--status-error-bg)]', icon: 'text-[var(--status-error-fg)]', badge: 'pn-badge--error', label: 'Crítico', is: OctagonAlert },
    warning: { box: 'border-[var(--status-warning-fg)]/30 bg-[var(--status-warning-bg)]', icon: 'text-[var(--status-warning-fg)]', badge: 'pn-badge--warning', label: 'Atenção', is: TriangleAlert },
    info: { box: 'border-[var(--border-strong)] bg-[var(--bg-muted)]/60', icon: 'text-[var(--status-info-fg)]', badge: 'pn-badge--info', label: 'Informação', is: Info },
};
const levelOf = (l) => LEVEL[l] || LEVEL.info;
const SPEND_SUB = 'Use a mesma origem e campanha das UTMs (ex.: origem "facebook", campanha "black-friday"). Sem campanha, o custo conta só no total da origem.';
const periodCaption =(d) => `últimos ${d} dias`;
const field = 'h-11 w-full rounded-full border border-[var(--border-strong)] bg-[var(--glass-bg)] px-4 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-tertiary)] outline-none transition focus:ring-4 focus:ring-[var(--focus-ring)] dark:[color-scheme:dark]';

const today = new Date().toLocaleDateString('en-CA');
const form = reactive({ spent_on: today, source: '', campaign: '', amount: '', note: '' });

function setDays(d) {
    router.get('/inteligencia', { days: d }, { preserveScroll: true, preserveState: true });
}
function addSpend() {
    router.post('/inteligencia/custos', form, { preserveScroll: true, onSuccess: () => Object.assign(form, { amount: '', note: '' }) });
}
function removeSpend(s) {
    if (!window.confirm('Remover este custo?')) return;
    router.delete(`/inteligencia/custos/${s.id}`, { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-6">
        <PnPageHeader
            title="Inteligência"
            :icon="Lightbulb"
            description="De onde vêm as vendas pagas, quanto custam e onde há dinheiro escapando. Receita conta só pagamento confirmado. ROAS e CAC aparecem apenas onde você informou o custo dos anúncios."
        >
            <template #actions>
                <div class="inline-flex rounded-full border border-[var(--border-strong)] bg-[var(--glass-bg)] p-1" role="group" aria-label="Período">
                    <button
                        v-for="d in periods"
                        :key="d"
                        type="button"
                        :aria-pressed="d === days"
                        class="inline-flex h-8 items-center gap-1.5 rounded-full px-4 text-[13px] transition focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)]"
                        :class="d === days ? 'bg-[var(--text-primary)] text-[var(--text-inverse)]' : 'text-[var(--text-secondary)] hover:text-[var(--text-primary)]'"
                        @click="setDays(d)"
                    >
                        <CalendarDays v-if="d === days" class="h-3.5 w-3.5" aria-hidden="true" /> {{ d }} dias
                    </button>
                </div>
            </template>
        </PnPageHeader>

        <!-- Indicadores -->
        <section class="kpi-row grid gap-4 sm:grid-cols-2 xl:grid-cols-5" aria-label="Indicadores do período">
            <PnKpiCard :index="0" label="Receita confirmada" :icon="Wallet" :value="brl(report.totals.revenue_cents)" caption="só pagamento confirmado" />
            <PnKpiCard :index="1" label="Vendas pagas" :icon="ShoppingBag" :value="num(report.totals.paid_orders)" :caption="periodCaption(days)" />
            <PnKpiCard :index="2" label="Clientes novos" :icon="UserPlus" :value="num(report.totals.new_customers)" :caption="periodCaption(days)" />
            <PnKpiCard :index="3" label="ROAS" :icon="TrendingUp" :value="roas(report.totals.roas)" caption="só com custo informado" />
            <PnKpiCard :index="4" label="CAC" :icon="Target" :value="brl(report.totals.cac_cents)" caption="só com custo informado" />
        </section>

        <!-- Alertas -->
        <PnPanel v-if="report.insights.length" title="Alertas" :sub="`${report.insights.length} ${report.insights.length === 1 ? 'ponto pede' : 'pontos pedem'} sua atenção`" :index="1">
            <ul class="grid gap-3 md:grid-cols-2">
                <li v-for="(i, idx) in report.insights" :key="idx" class="flex gap-3 rounded-[20px] border p-4" :class="levelOf(i.level).box">
                    <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[var(--glass-bg-strong)]" :class="levelOf(i.level).icon">
                        <component :is="levelOf(i.level).is" class="h-4 w-4" aria-hidden="true" />
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="text-sm font-medium text-[var(--text-primary)]">{{ i.title }}</p>
                            <span class="pn-badge pn-badge--nodot" :class="levelOf(i.level).badge">{{ levelOf(i.level).label }}</span>
                        </div>
                        <p class="mt-1 text-[13px] leading-5 text-[var(--text-secondary)]">{{ i.detail }}</p>
                        <a v-if="i.href" :href="i.href" class="mt-2 inline-flex items-center gap-1 rounded-full text-xs font-medium text-[var(--text-primary)] underline underline-offset-2 focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)]">
                            Abrir <ArrowUpRight class="h-3.5 w-3.5" aria-hidden="true" />
                        </a>
                    </div>
                </li>
            </ul>
        </PnPanel>

        <!-- Campanhas -->
        <PnPanel title="Campanhas" sub="Origem pela UTM do checkout. Custo vem do que você cadastrou abaixo." :index="2">
            <template #right>
                <span class="pn-badge pn-badge--neutral pn-badge--nodot shrink-0">{{ report.campaigns.length }} {{ report.campaigns.length === 1 ? 'linha' : 'linhas' }}</span>
            </template>
            <div v-if="!report.campaigns.length" class="flex flex-col items-center gap-2 rounded-[20px] border border-dashed border-[var(--border-strong)] px-6 py-8 text-center">
                <Megaphone class="h-6 w-6 text-[var(--text-tertiary)]" aria-hidden="true" />
                <p class="text-sm text-[var(--text-primary)]">Sem vendas pagas no período.</p>
                <p class="text-xs text-[var(--text-tertiary)]">Quando houver vendas com UTM, elas aparecem aqui por origem e campanha.</p>
            </div>
            <div v-else class="relative -mx-2 overflow-x-auto px-2">
                <table class="data-table w-full min-w-[720px] text-sm">
                    <thead>
                        <tr>
                            <th scope="col">Origem</th><th scope="col">Campanha</th><th scope="col" class="num">Vendas</th><th scope="col" class="num">Receita</th>
                            <th scope="col" class="num">Novos</th><th scope="col" class="num">Custo</th><th scope="col" class="num">ROAS</th><th scope="col" class="num">CAC</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="c in report.campaigns" :key="c.source + '|' + (c.campaign || '')">
                            <td><span class="pn-badge pn-badge--neutral pn-badge--nodot">{{ c.source }}</span></td>
                            <td class="max-w-[220px] truncate text-[var(--text-secondary)]">{{ c.campaign || '—' }}</td>
                            <td class="num">{{ c.paid_orders }}</td>
                            <td class="num font-medium text-[var(--text-primary)]">{{ brl(c.revenue_cents) }}</td>
                            <td class="num">{{ c.new_customers }}</td>
                            <td class="num">{{ brl(c.spend_cents) }}</td>
                            <td class="num">{{ roas(c.roas) }}</td>
                            <td class="num">{{ brl(c.cac_cents) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </PnPanel>

        <div class="grid gap-6 lg:grid-cols-2">
            <!-- Aprovação por método -->
            <PnPanel title="Aprovação por método" sub="Pedidos novos (sem renovações automáticas). Aprovados incluem os depois reembolsados." :index="3">
                <p v-if="!report.payments.length" class="rounded-[20px] border border-dashed border-[var(--border-strong)] px-4 py-6 text-center text-sm text-[var(--text-tertiary)]">Sem pedidos no período.</p>
                <div v-else class="relative -mx-2 overflow-x-auto px-2">
                    <table class="data-table w-full min-w-[480px] text-sm">
                        <thead>
                            <tr><th scope="col">Método</th><th scope="col" class="num">Tentativas</th><th scope="col">Aprovação</th><th scope="col" class="num">Pendentes</th><th scope="col" class="num">Receita</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="m in report.payments" :key="m.method">
                                <td class="font-medium text-[var(--text-primary)]">{{ METHOD[m.method] || m.method }}</td>
                                <td class="num">{{ m.attempts }}</td>
                                <td class="min-w-[130px]">
                                    <div class="flex items-center gap-2">
                                        <span class="w-12 shrink-0 tabular-nums text-[var(--text-primary)]">{{ pct(m.approval_rate) }}</span>
                                        <PnMeter v-if="m.approval_rate !== null && m.approval_rate !== undefined" :value="m.approval_rate * 100" :show-marker="false" class="flex-1 !h-1.5" />
                                    </div>
                                </td>
                                <td class="num">{{ m.pending }}</td>
                                <td class="num font-medium text-[var(--text-primary)]">{{ brl(m.revenue_cents) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </PnPanel>

            <!-- Produtos -->
            <PnPanel title="Produtos" sub="Perda = reembolsos e disputas sobre vendas aprovadas." :index="4">
                <p v-if="!report.products.length" class="rounded-[20px] border border-dashed border-[var(--border-strong)] px-4 py-6 text-center text-sm text-[var(--text-tertiary)]">Sem vendas de produtos no período.</p>
                <div v-else class="relative -mx-2 overflow-x-auto px-2">
                    <table class="data-table w-full min-w-[420px] text-sm">
                        <thead>
                            <tr><th scope="col">Produto</th><th scope="col" class="num">Pagas</th><th scope="col" class="num">Receita</th><th scope="col" class="num">Perda</th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="p in report.products" :key="p.product_id">
                                <td class="max-w-[200px] truncate font-medium text-[var(--text-primary)]" :title="p.name">{{ p.name }}</td>
                                <td class="num">{{ p.paid }}</td>
                                <td class="num">{{ brl(p.revenue_cents) }}</td>
                                <td class="num">{{ pct(p.loss_rate) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </PnPanel>
        </div>

        <!-- Custo dos anúncios -->
        <PnPanel
            v-if="can_edit_spend"
            title="Custo dos anúncios"
            :sub="SPEND_SUB"
            :index="5"
        >
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-[150px_1fr_1fr_130px_1fr_auto] xl:items-end">
                <label class="flex flex-col gap-1.5 text-xs text-[var(--text-secondary)]">
                    Data
                    <input v-model="form.spent_on" type="date" :max="today" :class="field" />
                </label>
                <label class="flex flex-col gap-1.5 text-xs text-[var(--text-secondary)]">
                    Origem
                    <input v-model="form.source" maxlength="100" placeholder="utm_source" :class="field" />
                </label>
                <label class="flex flex-col gap-1.5 text-xs text-[var(--text-secondary)]">
                    Campanha (opcional)
                    <input v-model="form.campaign" maxlength="191" placeholder="utm_campaign" :class="field" />
                </label>
                <label class="flex flex-col gap-1.5 text-xs text-[var(--text-secondary)]">
                    Valor (R$)
                    <input v-model="form.amount" inputmode="decimal" placeholder="0,00" :class="field" />
                </label>
                <label class="flex flex-col gap-1.5 text-xs text-[var(--text-secondary)]">
                    Observação
                    <input v-model="form.note" maxlength="255" placeholder="Opcional" :class="field" />
                </label>
                <button type="button" class="pn-btn pn-btn--primary h-11 sm:col-span-2 xl:col-span-1" :disabled="!form.source || !form.amount" @click="addSpend">
                    <Plus class="h-4 w-4" aria-hidden="true" /> Adicionar
                </button>
            </div>

            <div class="relative mt-5 -mx-2 overflow-x-auto px-2">
                <table class="data-table w-full min-w-[560px] text-sm">
                    <thead>
                        <tr><th scope="col">Data</th><th scope="col">Origem</th><th scope="col">Campanha</th><th scope="col" class="num">Valor</th><th scope="col">Obs.</th><th scope="col"><span class="sr-only">Ações</span></th></tr>
                    </thead>
                    <tbody>
                        <tr v-for="s in spends" :key="s.id">
                            <td class="tabular-nums">{{ s.spent_on.split('-').reverse().join('/') }}</td>
                            <td><span class="pn-badge pn-badge--neutral pn-badge--nodot">{{ s.source }}</span></td>
                            <td class="text-[var(--text-secondary)]">{{ s.campaign || '—' }}</td>
                            <td class="num font-medium text-[var(--text-primary)]">{{ brl(s.amount_cents) }}</td>
                            <td class="max-w-[180px] truncate text-[var(--text-tertiary)]" :title="s.note">{{ s.note }}</td>
                            <td class="text-right">
                                <button type="button" class="inline-flex h-8 w-8 items-center justify-center rounded-full text-[var(--text-tertiary)] transition hover:bg-[var(--status-error-bg)] hover:text-[var(--status-error-fg)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)]" aria-label="Remover" @click="removeSpend(s)">
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </td>
                        </tr>
                        <tr v-if="!spends.length"><td colspan="6" class="!py-6 text-center text-[var(--text-tertiary)]">Nenhum custo no período.</td></tr>
                    </tbody>
                </table>
            </div>
        </PnPanel>
        <p v-else class="flex items-start gap-2 rounded-[20px] border border-dashed border-[var(--border-strong)] px-4 py-3 text-xs text-[var(--text-tertiary)]">
            <Lock class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            ROAS e CAC ficam visíveis só para o dono da conta, porque o custo dos anúncios vale para todos os produtos.
        </p>
    </div>
</template>

<style scoped>
.kpi-row :deep(.pn-kpi) {
    container-type: inline-size;
}
.kpi-row :deep(.pn-kpi__value) {
    /* Tamanho acompanha a largura do card; só quebra no espaço depois de "R$", nunca no meio do número. */
    font-size: clamp(18px, 9.5cqi, 32px);
    line-height: 1.2;
    overflow-wrap: normal;
}
.data-table th {
    padding: 0 12px 10px;
    text-align: left;
    font-size: 11px;
    font-weight: 400;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: var(--text-tertiary);
    white-space: nowrap;
}
.data-table td {
    padding: 11px 12px;
    border-top: 1px solid var(--border-strong);
    color: var(--text-secondary);
    white-space: nowrap;
}
.data-table th:first-child,
.data-table td:first-child {
    padding-left: 0;
}
.data-table th:last-child,
.data-table td:last-child {
    padding-right: 0;
}
.data-table .num {
    text-align: right;
    font-variant-numeric: tabular-nums;
}
.data-table tbody tr {
    transition: background-color 150ms;
}
.data-table tbody tr:hover td {
    background: color-mix(in srgb, var(--bg-muted) 45%, transparent);
}
</style>
