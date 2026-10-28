<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';
import { AlertTriangle, Landmark, Loader2, RefreshCw, ShieldAlert } from 'lucide-vue-next';

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

const SEVERITY = {
    critical: { label: 'Crítico', cls: 'bg-red-100 text-red-800 dark:bg-red-950/40 dark:text-red-300' },
    high: { label: 'Alto', cls: 'bg-orange-100 text-orange-800 dark:bg-orange-950/40 dark:text-orange-300' },
    attention: { label: 'Atenção', cls: 'bg-amber-100 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300' },
    info: { label: 'Informativo', cls: 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300' },
};
const STATUS = { open: 'Divergente', investigating: 'Em investigação', resolved: 'Conciliado', ignored: 'Ignorado' };
const STATUS_FILTERS = [
    { value: 'active', label: 'Abertas' },
    { value: 'investigating', label: 'Em investigação' },
    { value: 'resolved', label: 'Conciliadas' },
    { value: 'ignored', label: 'Ignoradas' },
    { value: 'all', label: 'Todas' },
];

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
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Financeiro</p>
                <h1 class="mt-1 flex items-center gap-2 text-2xl font-bold text-zinc-900 dark:text-white">
                    <Landmark class="h-6 w-6" /> Tesouraria e conciliação
                </h1>
                <p class="mt-2 max-w-2xl text-sm text-zinc-600 dark:text-zinc-400">
                    Confronta pedidos, carteiras, split, MED e saques. Modo observação: nada aqui movimenta saldo — corrigir é
                    uma decisão sua, registrada na auditoria.
                </p>
            </div>
            <Button type="button" class="gap-2" :disabled="running" @click="runNow">
                <Loader2 v-if="running" class="h-4 w-4 animate-spin" />
                <RefreshCw v-else class="h-4 w-4" />
                Conciliar agora
            </Button>
        </div>

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80">
                <p class="text-xs text-zinc-500">Divergências abertas</p>
                <p class="mt-1 text-2xl font-semibold">{{ summary.active }}</p>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80">
                <p class="text-xs text-zinc-500">Críticas</p>
                <p class="mt-1 text-2xl font-semibold" :class="summary.critical ? 'text-red-600' : ''">{{ summary.critical }}</p>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80">
                <p class="text-xs text-zinc-500">Valor divergente (crítico + alto)</p>
                <p class="mt-1 text-2xl font-semibold">{{ brl(summary.amount_cents) }}</p>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80">
                <p class="text-xs text-zinc-500">Saques em investigação</p>
                <p class="mt-1 text-2xl font-semibold">{{ summary.withdrawals_investigating }}</p>
            </div>
        </div>

        <p class="text-xs text-zinc-500">
            Última conciliação:
            <template v-if="last_run">
                {{ dt(last_run.finished_at || last_run.started_at) }} —
                <span :class="last_run.status === 'failed' ? 'text-red-600' : ''">{{ last_run.status === 'done' ? 'concluída' : last_run.status === 'failed' ? 'falhou' : 'em andamento' }}</span>
                <template v-if="last_run.counts">· {{ last_run.counts.new || 0 }} novas, {{ last_run.counts.auto_resolved || 0 }} encerradas</template>
            </template>
            <template v-else>ainda não executada (roda de hora em hora).</template>
        </p>

        <div class="flex flex-wrap items-center gap-2">
            <nav class="pn-seg max-w-full overflow-x-auto" aria-label="Status">
                <button
                    v-for="s in STATUS_FILTERS"
                    :key="s.value"
                    type="button"
                    class="pn-tab pn-keep-shape"
                    :class="filters.status === s.value ? 'pn-tab--active' : ''"
                    @click="applyFilter({ status: s.value })"
                >
                    {{ s.label }}
                </button>
            </nav>
            <select
                class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                :value="filters.severity || ''"
                @change="applyFilter({ severity: $event.target.value || null })"
            >
                <option value="">Todas as severidades</option>
                <option v-for="(v, k) in SEVERITY" :key="k" :value="k">{{ v.label }}</option>
            </select>
            <select
                class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"
                :value="filters.kind || ''"
                @change="applyFilter({ kind: $event.target.value || null })"
            >
                <option value="">Todos os tipos</option>
                <option v-for="k in kinds" :key="k.key" :value="k.key">{{ k.label }}</option>
            </select>
        </div>

        <div v-if="!rows.length" class="flex flex-col items-center gap-2 rounded-2xl border border-dashed border-zinc-300 py-14 text-center dark:border-zinc-700">
            <ShieldAlert class="h-8 w-8 text-zinc-400" />
            <p class="text-sm text-zinc-600 dark:text-zinc-400">Nenhuma divergência neste filtro.</p>
        </div>

        <ul v-else class="space-y-2">
            <li v-for="row in rows" :key="row.id" class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-medium" :class="SEVERITY[row.severity]?.cls">{{ SEVERITY[row.severity]?.label || row.severity }}</span>
                            <span class="text-xs text-zinc-500">{{ STATUS[row.status] || row.status }}</span>
                        </div>
                        <p class="mt-1 font-medium text-zinc-900 dark:text-white">{{ row.label }}</p>
                        <p class="mt-0.5 text-xs text-zinc-500">
                            <template v-if="row.tenant_id">
                                <Link :href="`/plataforma/usuarios/${row.tenant_id}`" class="hover:underline">{{ row.tenant_name || `Seller #${row.tenant_id}` }}</Link> ·
                            </template>
                            <template v-if="row.order_id">Pedido #{{ row.order_id }} · </template>
                            <template v-if="row.withdrawal_id">Saque #{{ row.withdrawal_id }} · </template>
                            visto {{ dt(row.first_seen_at) }}<template v-if="row.last_seen_at !== row.first_seen_at"> até {{ dt(row.last_seen_at) }}</template>
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="font-semibold tabular-nums">{{ brl(row.amount_cents) }}</p>
                        <div class="mt-2 flex flex-wrap justify-end gap-1.5">
                            <Button v-if="row.status === 'open'" size="sm" variant="outline" @click="openEdit(row, 'investigating')">Investigar</Button>
                            <Button v-if="row.status === 'open' || row.status === 'investigating'" size="sm" variant="outline" @click="openEdit(row, 'resolved')">Marcar conciliada</Button>
                            <Button v-if="row.status === 'open' || row.status === 'investigating'" size="sm" variant="ghost" @click="openEdit(row, 'ignored')">Ignorar</Button>
                            <Button v-if="row.status === 'resolved' || row.status === 'ignored'" size="sm" variant="ghost" @click="openEdit(row, 'open')">Reabrir</Button>
                        </div>
                    </div>
                </div>
                <button
                    v-if="detailText(row) || row.resolution_note"
                    type="button"
                    class="mt-2 text-xs text-[var(--color-primary)] hover:underline"
                    @click="expanded[row.id] = !expanded[row.id]"
                >
                    {{ expanded[row.id] ? 'Ocultar detalhes' : 'Detalhes' }}
                </button>
                <div v-if="expanded[row.id]" class="mt-2 space-y-1 rounded-xl bg-zinc-50 p-3 text-xs text-zinc-600 dark:bg-zinc-800/60 dark:text-zinc-300">
                    <p v-if="detailText(row)">{{ detailText(row) }}</p>
                    <p v-if="row.resolution_note" class="whitespace-pre-line">{{ row.resolution_note }}</p>
                </div>
            </li>
        </ul>

        <nav v-if="discrepancies.links && discrepancies.total > rows.length" class="flex flex-wrap gap-1">
            <Link
                v-for="(l, i) in discrepancies.links"
                :key="i"
                :href="l.url || ''"
                preserve-scroll
                class="rounded-lg px-3 py-1.5 text-sm"
                :class="[l.active ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'text-zinc-600', !l.url ? 'pointer-events-none opacity-40' : '']"
                v-html="l.label"
            />
        </nav>

        <div v-if="editing" class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-4 sm:items-center" @click.self="editing = null">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 dark:bg-zinc-900">
                <p class="flex items-center gap-2 font-semibold"><AlertTriangle class="h-4 w-4" /> {{ editing.label }}</p>
                <p class="mt-1 text-sm text-zinc-500">Novo status: <b>{{ STATUS[editStatus] }}</b>. Isso não altera saldo — só registra a decisão.</p>
                <textarea
                    v-model="editNote"
                    rows="3"
                    maxlength="2000"
                    placeholder="Justificativa (obrigatória, mín. 5 caracteres)"
                    class="mt-3 w-full rounded-xl border border-zinc-200 bg-white p-3 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                />
                <div class="mt-3 flex justify-end gap-2">
                    <Button variant="ghost" @click="editing = null">Cancelar</Button>
                    <Button :disabled="saving || editNote.trim().length < 5" @click="saveEdit">Salvar</Button>
                </div>
            </div>
        </div>
    </div>
</template>
