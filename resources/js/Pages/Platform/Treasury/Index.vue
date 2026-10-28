<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import PnPageHeader from '@/components/paynext/PnPageHeader.vue';
import PnKpiCard from '@/components/paynext/PnKpiCard.vue';
import PnEmptyState from '@/components/paynext/PnEmptyState.vue';
import { AlertTriangle, Banknote, ChevronDown, Clock, Landmark, Loader2, RefreshCw, Scale, ShieldAlert, Wallet } from 'lucide-vue-next';

defineOptions({ layout: LayoutPlatform });

/**
 * Brasa Treasury — divergências da conciliação. Modo observação: as ações só mudam o status
 * da divergência (com justificativa, auditada); nenhum saldo é movimentado daqui.
 */
const props = defineProps({
    filters: { type: Object, required: true },
    kinds: { type: Array, default: () => [] },
    summary: { type: Object, required: true },
    last_run: { type: Object, default: null },
    discrepancies: { type: Object, required: true },
});

/* Severidade: crítico vermelho, alto laranja, atenção âmbar, informativo neutro. */
const SEVERITY = {
    critical: { label: 'Crítico', cls: 'pn-badge pn-badge--error', stripe: 'bg-red-500' },
    high: { label: 'Alto', cls: 'pn-badge bg-orange-500/15 text-orange-700 dark:text-orange-300', stripe: 'bg-orange-500' },
    attention: { label: 'Atenção', cls: 'pn-badge pn-badge--warning', stripe: 'bg-amber-400' },
    info: { label: 'Informativo', cls: 'pn-badge pn-badge--neutral', stripe: 'bg-[var(--ink-300)]' },
};
const STATUS = { open: 'Divergente', investigating: 'Em investigação', resolved: 'Conciliado', ignored: 'Ignorado' };
const STATUS_BADGE = {
    open: 'pn-badge pn-badge--error pn-badge--nodot',
    investigating: 'pn-badge pn-badge--info pn-badge--nodot',
    resolved: 'pn-badge pn-badge--success pn-badge--nodot',
    ignored: 'pn-badge pn-badge--neutral pn-badge--nodot',
};
const STATUS_FILTERS = [
    { value: 'active', label: 'Abertas' },
    { value: 'investigating', label: 'Em investigação' },
    { value: 'resolved', label: 'Conciliadas' },
    { value: 'ignored', label: 'Ignoradas' },
    { value: 'all', label: 'Todas' },
];
const SELECT = 'h-10 rounded-full border border-[var(--border-strong)] bg-[var(--glass-bg)] px-4 text-sm text-[var(--text-primary)] outline-none focus:ring-4 focus:ring-[var(--focus-ring)]';

const running = ref(false);
const editing = ref(null);
const editStatus = ref('investigating');
const editNote = ref('');
const saving = ref(false);
const expanded = ref({});

const brl = (cents) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format((Number(cents) || 0) / 100);
const dt = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR') : '—');
const rows = computed(() => props.discrepancies?.data ?? []);

function applyFilter(patch) {
    router.get('/plataforma/tesouraria', { ...props.filters, ...patch }, { preserveScroll: true, preserveState: true });
}

function runNow() {
    running.value = true;
    router.post('/plataforma/tesouraria/executar', {}, { preserveScroll: true, onFinish: () => (running.value = false) });
}

function openEdit(row, status) {
    editing.value = row;
    editStatus.value = status;
    editNote.value = '';
}

function saveEdit() {
    if (!editing.value || editNote.value.trim().length < 5) return;
    saving.value = true;
    router.put(
        `/plataforma/tesouraria/divergencias/${editing.value.id}`,
        { status: editStatus.value, note: editNote.value.trim() },
        { preserveScroll: true, onSuccess: () => (editing.value = null), onFinish: () => (saving.value = false) }
    );
}

function detailText(row) {
    const d = row.details || {};
    return Object.entries(d)
        .filter(([, v]) => v !== null && v !== '')
        .map(([k, v]) => `${k}: ${k.endsWith('_cents') ? brl(v) : k.endsWith('_at') ? dt(v) : v}`)
        .join(' · ');
}
</script>

<template>
    <div class="mx-auto max-w-6xl space-y-6">
        <PnPageHeader eyebrow="Financeiro" title="Tesouraria e conciliação" :icon="Landmark"
            description="Confronta pedidos, carteiras, split, MED e saques. Modo observação: nada aqui movimenta saldo — corrigir é uma decisão sua, registrada na auditoria.">
            <template #actions>
                <button type="button" class="pn-btn pn-btn--primary pn-btn--sm !w-auto" :disabled="running" @click="runNow">
                    <Loader2 v-if="running" class="h-4 w-4 animate-spin" aria-hidden="true" />
                    <RefreshCw v-else class="h-4 w-4" aria-hidden="true" />
                    Conciliar agora
                </button>
            </template>
        </PnPageHeader>

        <!-- Indicadores -->
        <section class="tr-kpis grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4" aria-label="Resumo da conciliação">
            <PnKpiCard label="Divergências abertas" :value="summary.active" :icon="Scale" :index="0" />
            <PnKpiCard label="Críticas" :value="summary.critical" :icon="ShieldAlert" :class="summary.critical ? 'tr-kpi--critical' : ''" :index="1" />
            <PnKpiCard label="Valor divergente" :value="brl(summary.amount_cents)" :icon="Wallet" caption="crítico + alto" :index="2" />
            <PnKpiCard label="Saques em investigação" :value="summary.withdrawals_investigating" :icon="Banknote" :index="3" />
        </section>

        <!-- Última execução -->
        <p class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs text-[var(--text-tertiary)]">
            <Clock class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
            Última conciliação:
            <template v-if="last_run">
                <span class="text-[var(--text-secondary)]">{{ dt(last_run.finished_at || last_run.started_at) }}</span>
                <span :class="last_run.status === 'failed' ? 'pn-badge pn-badge--error' : last_run.status === 'done' ? 'pn-badge pn-badge--success' : 'pn-badge pn-badge--info'">{{ last_run.status === 'done' ? 'concluída' : last_run.status === 'failed' ? 'falhou' : 'em andamento' }}</span>
                <template v-if="last_run.counts"><span>{{ last_run.counts.new || 0 }} novas, {{ last_run.counts.auto_resolved || 0 }} encerradas</span></template>
            </template>
            <template v-else>ainda não executada (roda de hora em hora).</template>
        </p>

        <!-- Filtros -->
        <div class="flex flex-wrap items-center gap-2">
            <nav class="relative pn-seg max-w-full overflow-x-auto" aria-label="Status">
                <button
                    v-for="s in STATUS_FILTERS"
                    :key="s.value"
                    type="button"
                    class="pn-tab pn-keep-shape focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)]"
                    :class="filters.status === s.value ? 'pn-tab--active' : ''"
                    :aria-pressed="filters.status === s.value"
                    @click="applyFilter({ status: s.value })"
                >
                    {{ s.label }}
                </button>
            </nav>
            <select
                aria-label="Severidade"
                :class="SELECT"
                :value="filters.severity || ''"
                @change="applyFilter({ severity: $event.target.value || null })"
            >
                <option value="">Todas as severidades</option>
                <option v-for="(v, k) in SEVERITY" :key="k" :value="k">{{ v.label }}</option>
            </select>
            <select
                aria-label="Tipo de divergência"
                class="max-w-full"
                :class="SELECT"
                :value="filters.kind || ''"
                @change="applyFilter({ kind: $event.target.value || null })"
            >
                <option value="">Todos os tipos</option>
                <option v-for="k in kinds" :key="k.key" :value="k.key">{{ k.label }}</option>
            </select>
        </div>

        <PnEmptyState v-if="!rows.length" title="Nenhuma divergência neste filtro" description="Troque o status, a severidade ou o tipo acima para ver outras divergências." :icon="ShieldAlert" />

        <ul v-else class="space-y-2">
            <li v-for="row in rows" :key="row.id" class="pn-glass relative overflow-hidden py-4 pl-5 pr-4">
                <span class="absolute inset-y-0 left-0 w-1" :class="SEVERITY[row.severity]?.stripe || 'bg-[var(--ink-300)]'" aria-hidden="true" />
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span :class="SEVERITY[row.severity]?.cls || 'pn-badge pn-badge--neutral'">{{ SEVERITY[row.severity]?.label || row.severity }}</span>
                            <span :class="STATUS_BADGE[row.status] || 'pn-badge pn-badge--neutral pn-badge--nodot'">{{ STATUS[row.status] || row.status }}</span>
                        </div>
                        <p class="mt-1.5 font-medium text-[var(--text-primary)]">{{ row.label }}</p>
                        <p class="mt-0.5 text-xs text-[var(--text-tertiary)]">
                            <template v-if="row.tenant_id">
                                <Link :href="`/plataforma/usuarios/${row.tenant_id}`" class="rounded-sm text-[var(--text-secondary)] hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--focus-ring)]">{{ row.tenant_name || `Seller #${row.tenant_id}` }}</Link> ·
                            </template>
                            <template v-if="row.order_id">Pedido #{{ row.order_id }} · </template>
                            <template v-if="row.withdrawal_id">Saque #{{ row.withdrawal_id }} · </template>
                            visto {{ dt(row.first_seen_at) }}<template v-if="row.last_seen_at !== row.first_seen_at"> até {{ dt(row.last_seen_at) }}</template>
                        </p>
                    </div>
                    <div class="flex w-full flex-col items-start gap-2 sm:w-auto sm:items-end">
                        <p class="text-lg font-medium tabular-nums text-[var(--text-primary)]">{{ brl(row.amount_cents) }}</p>
                        <div class="flex flex-wrap gap-1.5 sm:justify-end">
                            <button v-if="row.status === 'open'" type="button" class="pn-btn pn-btn--secondary pn-btn--sm !w-auto" @click="openEdit(row, 'investigating')">Investigar</button>
                            <button v-if="row.status === 'open' || row.status === 'investigating'" type="button" class="pn-btn pn-btn--secondary pn-btn--sm !w-auto" @click="openEdit(row, 'resolved')">Marcar conciliada</button>
                            <button v-if="row.status === 'open' || row.status === 'investigating'" type="button" class="pn-btn pn-btn--ghost pn-btn--sm !w-auto" @click="openEdit(row, 'ignored')">Ignorar</button>
                            <button v-if="row.status === 'resolved' || row.status === 'ignored'" type="button" class="pn-btn pn-btn--ghost pn-btn--sm !w-auto" @click="openEdit(row, 'open')">Reabrir</button>
                        </div>
                    </div>
                </div>
                <button
                    v-if="detailText(row) || row.resolution_note"
                    type="button"
                    class="mt-2 inline-flex items-center gap-1 rounded-full text-xs text-[var(--text-secondary)] hover:text-[var(--text-primary)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--focus-ring)]"
                    :aria-expanded="!!expanded[row.id]"
                    @click="expanded[row.id] = !expanded[row.id]"
                >
                    {{ expanded[row.id] ? 'Ocultar detalhes' : 'Detalhes' }}
                    <ChevronDown class="h-3.5 w-3.5 transition-transform" :class="expanded[row.id] ? 'rotate-180' : ''" aria-hidden="true" />
                </button>
                <div v-if="expanded[row.id]" class="mt-2 space-y-1 break-words rounded-2xl bg-[var(--bg-muted)]/60 p-3 text-xs text-[var(--text-secondary)]">
                    <p v-if="detailText(row)">{{ detailText(row) }}</p>
                    <p v-if="row.resolution_note" class="whitespace-pre-line">{{ row.resolution_note }}</p>
                </div>
            </li>
        </ul>

        <nav v-if="discrepancies.links && discrepancies.total > rows.length" class="flex flex-wrap justify-center gap-1" aria-label="Páginas">
            <Link
                v-for="(l, i) in discrepancies.links"
                :key="i"
                :href="l.url || ''"
                preserve-scroll
                class="rounded-full px-3.5 py-1.5 text-sm"
                :class="[l.active ? 'bg-[var(--text-primary)] text-[var(--text-inverse)]' : 'text-[var(--text-secondary)] hover:bg-[var(--glass-bg)]', !l.url ? 'pointer-events-none opacity-40' : '']"
                v-html="l.label"
            />
        </nav>

        <div v-if="editing" class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-4 backdrop-blur-[2px] sm:items-center" @click.self="editing = null">
            <div class="w-full max-w-md rounded-[28px] border border-[var(--border-strong)] bg-[var(--bg-surface,#fff)] p-6 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="tr-edit-title">
                <p id="tr-edit-title" class="flex items-center gap-2 font-medium text-[var(--text-primary)]"><AlertTriangle class="h-4 w-4 shrink-0 text-amber-500" aria-hidden="true" /> {{ editing.label }}</p>
                <p class="mt-1.5 text-sm text-[var(--text-secondary)]">Novo status: <b class="font-medium text-[var(--text-primary)]">{{ STATUS[editStatus] }}</b>. Isso não altera saldo — só registra a decisão.</p>
                <label for="tr-edit-note" class="mt-4 block text-xs text-[var(--text-secondary)]">Justificativa (obrigatória, mín. 5 caracteres)</label>
                <textarea
                    id="tr-edit-note"
                    v-model="editNote"
                    rows="3"
                    maxlength="2000"
                    placeholder="Justificativa (obrigatória, mín. 5 caracteres)"
                    class="mt-1.5 w-full rounded-2xl border border-[var(--border-strong)] bg-transparent p-3 text-sm text-[var(--text-primary)] outline-none placeholder:text-[var(--text-tertiary)] focus:ring-4 focus:ring-[var(--focus-ring)]"
                />
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" class="pn-btn pn-btn--ghost pn-btn--sm !w-auto" @click="editing = null">Cancelar</button>
                    <button type="button" class="pn-btn pn-btn--primary pn-btn--sm !w-auto" :disabled="saving || editNote.trim().length < 5" @click="saveEdit">Salvar</button>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.tr-kpis :deep(.pn-kpi) {
    container-type: inline-size;
}
.tr-kpis :deep(.pn-kpi__value) {
    /* Tamanho acompanha a largura do card; só quebra no espaço depois de "R$", nunca no meio do número. */
    font-size: clamp(18px, 9.5cqi, 32px);
    line-height: 1.2;
    overflow-wrap: normal;
}
.tr-kpi--critical :deep(.pn-kpi__value) {
    color: var(--status-error-fg);
}
</style>
