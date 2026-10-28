<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import PnPageHeader from '@/components/paynext/PnPageHeader.vue';
import PnKpiCard from '@/components/paynext/PnKpiCard.vue';
import PnEmptyState from '@/components/paynext/PnEmptyState.vue';
import { Activity, ShieldAlert, ShieldCheck, TriangleAlert } from 'lucide-vue-next';

defineOptions({ layout: LayoutPlatform });

/**
 * BrasaShield — fila de análise. Modo observação: o score e a ação sugerida não bloqueiam nada;
 * a decisão é do admin e fica na auditoria.
 */
const props = defineProps({
    filters: { type: Object, required: true },
    summary: { type: Object, required: true },
    items: { type: Object, required: true },
});

/* Nível de risco: alto vermelho, médio âmbar, baixo neutro. */
const LEVEL = {
    high: { label: 'Alto', cls: 'pn-badge pn-badge--error', stripe: 'bg-red-500' },
    medium: { label: 'Médio', cls: 'pn-badge pn-badge--warning', stripe: 'bg-amber-400' },
    low: { label: 'Baixo', cls: 'pn-badge pn-badge--neutral', stripe: 'bg-[var(--ink-300)]' },
};
const ACTION = { review: 'Revisar', monitor: 'Monitorar', allow: 'Permitir' };
const STATUS = { open: 'Aberto', reviewed: 'Revisado', dismissed: 'Descartado' };
const STATUS_BADGE = {
    open: 'pn-badge pn-badge--info pn-badge--nodot',
    reviewed: 'pn-badge pn-badge--success pn-badge--nodot',
    dismissed: 'pn-badge pn-badge--neutral pn-badge--nodot',
};
const SELECT = 'h-10 rounded-full border border-[var(--border-strong)] bg-[var(--glass-bg)] px-4 text-sm text-[var(--text-primary)] outline-none focus:ring-4 focus:ring-[var(--focus-ring)]';
const brl = (c) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format((Number(c) || 0) / 100);
const rows = computed(() => props.items?.data ?? []);

const editing = ref(null);
const editStatus = ref('reviewed');
const note = ref('');
const saving = ref(false);

function filter(patch) {
    router.get('/plataforma/risco', { ...props.filters, ...patch }, { preserveScroll: true, preserveState: true });
}
function open(row, status) {
    editing.value = row;
    editStatus.value = status;
    note.value = '';
}
function save() {
    if (note.value.trim().length < 5) return;
    saving.value = true;
    router.put(`/plataforma/risco/${editing.value.id}`, { status: editStatus.value, note: note.value.trim() }, {
        preserveScroll: true,
        onSuccess: () => (editing.value = null),
        onFinish: () => (saving.value = false),
    });
}
function subjectLink(row) {
    return row.subject_type === 'withdrawal' ? '/plataforma/saques' : `/plataforma/transacoes?q=${row.subject_id}`;
}
</script>

<template>
    <div class="mx-auto max-w-6xl space-y-6">
        <PnPageHeader eyebrow="Risco" title="BrasaShield" :icon="ShieldAlert"
            description="Score explicável a partir de dados da própria plataforma (velocidade de compras, recusas, reembolsos, MED, padrão do seller). Modo observação: nada é bloqueado ou retido automaticamente. Complementa — não substitui — o antifraude das adquirentes." />

        <section class="grid grid-cols-1 gap-3 sm:grid-cols-3" aria-label="Resumo da fila">
            <PnKpiCard label="Alto risco em aberto" :value="summary.high_open" :icon="TriangleAlert" :class="summary.high_open ? 'rk-kpi--high' : ''" :index="0" />
            <PnKpiCard label="Médio em aberto" :value="summary.medium_open" :icon="ShieldAlert" :index="1" />
            <PnKpiCard label="Avaliados em 24 h" :value="summary.assessed_24h" :icon="Activity" :index="2" />
        </section>

        <div class="flex flex-wrap gap-2">
            <select aria-label="Status" :class="SELECT" :value="filters.status" @change="filter({ status: $event.target.value })">
                <option value="open">Abertos</option><option value="reviewed">Revisados</option><option value="dismissed">Descartados</option><option value="all">Todos</option>
            </select>
            <select aria-label="Nível de risco" :class="SELECT" :value="filters.level || ''" @change="filter({ level: $event.target.value || null })">
                <option value="">Médio e alto</option><option value="high">Só alto</option><option value="medium">Só médio</option>
            </select>
            <select aria-label="Tipo" :class="SELECT" :value="filters.type || ''" @change="filter({ type: $event.target.value || null })">
                <option value="">Pedidos e saques</option><option value="order">Pedidos</option><option value="withdrawal">Saques</option>
            </select>
        </div>

        <PnEmptyState v-if="!rows.length" title="Nada para analisar neste filtro" description="Troque o status, o nível ou o tipo acima para ver outros casos." :icon="ShieldCheck" />

        <ul v-else class="space-y-2">
            <li v-for="row in rows" :key="row.id" class="pn-glass relative overflow-hidden py-4 pl-5 pr-4">
                <span class="absolute inset-y-0 left-0 w-1" :class="LEVEL[row.level]?.stripe || 'bg-[var(--ink-300)]'" aria-hidden="true" />
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span :class="LEVEL[row.level]?.cls || 'pn-badge pn-badge--neutral'">{{ LEVEL[row.level]?.label }} · <b class="font-medium tabular-nums">{{ row.score }}</b></span>
                            <span :class="STATUS_BADGE[row.status] || 'pn-badge pn-badge--neutral pn-badge--nodot'">{{ STATUS[row.status] }}</span>
                            <span class="text-xs text-[var(--text-tertiary)]">sugestão: {{ ACTION[row.suggested_action] }}</span>
                        </div>
                        <p class="mt-1.5 font-medium text-[var(--text-primary)]">
                            <Link :href="subjectLink(row)" class="rounded-sm hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--focus-ring)]">{{ row.subject_type === 'withdrawal' ? 'Saque' : 'Pedido' }} #{{ row.subject_id }}</Link>
                            <template v-if="row.tenant_id"> · <Link :href="`/plataforma/usuarios/${row.tenant_id}`" class="rounded-sm font-normal text-[var(--text-secondary)] hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--focus-ring)]">{{ row.tenant_name || `Seller #${row.tenant_id}` }}</Link></template>
                        </p>
                        <ul class="mt-2 space-y-1 text-xs text-[var(--text-secondary)]">
                            <li v-for="s in row.signals" :key="s.code" class="flex items-baseline gap-2">
                                <span class="shrink-0 rounded-full bg-[var(--bg-muted)] px-1.5 py-px font-medium tabular-nums text-[var(--text-primary)]">+{{ s.weight }}</span>
                                <span class="min-w-0">{{ s.label }} <span class="text-[var(--text-tertiary)]">({{ s.detail }})</span></span>
                            </li>
                        </ul>
                        <p v-if="row.review_note" class="mt-2 whitespace-pre-line rounded-2xl bg-[var(--bg-muted)]/60 p-3 text-xs text-[var(--text-secondary)]">{{ row.review_note }}</p>
                    </div>
                    <div class="flex w-full flex-col items-start gap-2 sm:w-auto sm:items-end">
                        <p class="text-lg font-medium tabular-nums text-[var(--text-primary)]">{{ brl(row.amount_cents) }}</p>
                        <div class="flex flex-wrap gap-1.5 sm:justify-end">
                            <button v-if="row.status === 'open'" type="button" class="pn-btn pn-btn--secondary pn-btn--sm !w-auto" @click="open(row, 'reviewed')">Marcar revisado</button>
                            <button v-if="row.status === 'open'" type="button" class="pn-btn pn-btn--ghost pn-btn--sm !w-auto" @click="open(row, 'dismissed')">Descartar</button>
                            <button v-else type="button" class="pn-btn pn-btn--ghost pn-btn--sm !w-auto" @click="open(row, 'open')">Reabrir</button>
                        </div>
                    </div>
                </div>
            </li>
        </ul>

        <div v-if="editing" class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-4 backdrop-blur-[2px] sm:items-center" @click.self="editing = null">
            <div class="w-full max-w-md rounded-[28px] border border-[var(--border-strong)] bg-[var(--bg-surface,#fff)] p-6 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="rk-edit-title">
                <p id="rk-edit-title" class="font-medium text-[var(--text-primary)]">{{ editing.subject_type === 'withdrawal' ? 'Saque' : 'Pedido' }} #{{ editing.subject_id }} → {{ STATUS[editStatus] }}</p>
                <p class="mt-1.5 text-sm text-[var(--text-secondary)]">A decisão fica registrada na auditoria. Nada é bloqueado ou retido por aqui.</p>
                <label for="rk-edit-note" class="mt-4 block text-xs text-[var(--text-secondary)]">O que foi verificado (obrigatório, mín. 5 caracteres)</label>
                <textarea id="rk-edit-note" v-model="note" rows="3" maxlength="2000" placeholder="O que foi verificado (obrigatório)"
                    class="mt-1.5 w-full rounded-2xl border border-[var(--border-strong)] bg-transparent p-3 text-sm text-[var(--text-primary)] outline-none placeholder:text-[var(--text-tertiary)] focus:ring-4 focus:ring-[var(--focus-ring)]" />
                <div class="mt-4 flex justify-end gap-2">
                    <button type="button" class="pn-btn pn-btn--ghost pn-btn--sm !w-auto" @click="editing = null">Cancelar</button>
                    <button type="button" class="pn-btn pn-btn--primary pn-btn--sm !w-auto" :disabled="saving || note.trim().length < 5" @click="save">Salvar</button>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.rk-kpi--high :deep(.pn-kpi__value) {
    color: var(--status-error-fg);
}
</style>
