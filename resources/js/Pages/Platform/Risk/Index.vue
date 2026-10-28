<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';
import { ShieldAlert } from 'lucide-vue-next';

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

const LEVEL = {
    high: { label: 'Alto', cls: 'bg-red-100 text-red-800 dark:bg-red-950/40 dark:text-red-300' },
    medium: { label: 'Médio', cls: 'bg-amber-100 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300' },
    low: { label: 'Baixo', cls: 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300' },
};
const ACTION = { review: 'Revisar', monitor: 'Monitorar', allow: 'Permitir' };
const STATUS = { open: 'Aberto', reviewed: 'Revisado', dismissed: 'Descartado' };
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
        <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Risco</p>
            <h1 class="mt-1 flex items-center gap-2 text-2xl font-bold text-zinc-900 dark:text-white"><ShieldAlert class="h-6 w-6" /> BrasaShield</h1>
            <p class="mt-2 max-w-2xl text-sm text-zinc-600 dark:text-zinc-400">
                Score explicável a partir de dados da própria plataforma (velocidade de compras, recusas, reembolsos, MED,
                padrão do seller). Modo observação: nada é bloqueado ou retido automaticamente. Complementa — não
                substitui — o antifraude das adquirentes.
            </p>
        </div>

        <div class="grid grid-cols-3 gap-3">
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80"><p class="text-xs text-zinc-500">Alto risco em aberto</p><p class="mt-1 text-2xl font-semibold" :class="summary.high_open ? 'text-red-600' : ''">{{ summary.high_open }}</p></div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80"><p class="text-xs text-zinc-500">Médio em aberto</p><p class="mt-1 text-2xl font-semibold">{{ summary.medium_open }}</p></div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80"><p class="text-xs text-zinc-500">Avaliados em 24 h</p><p class="mt-1 text-2xl font-semibold">{{ summary.assessed_24h }}</p></div>
        </div>

        <div class="flex flex-wrap gap-2 text-sm">
            <select class="rounded-xl border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-900" :value="filters.status" @change="filter({ status: $event.target.value })">
                <option value="open">Abertos</option><option value="reviewed">Revisados</option><option value="dismissed">Descartados</option><option value="all">Todos</option>
            </select>
            <select class="rounded-xl border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-900" :value="filters.level || ''" @change="filter({ level: $event.target.value || null })">
                <option value="">Médio e alto</option><option value="high">Só alto</option><option value="medium">Só médio</option>
            </select>
            <select class="rounded-xl border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-900" :value="filters.type || ''" @change="filter({ type: $event.target.value || null })">
                <option value="">Pedidos e saques</option><option value="order">Pedidos</option><option value="withdrawal">Saques</option>
            </select>
        </div>

        <p v-if="!rows.length" class="rounded-2xl border border-dashed border-zinc-300 py-12 text-center text-sm text-zinc-500 dark:border-zinc-700">Nada para analisar neste filtro.</p>

        <ul v-else class="space-y-2">
            <li v-for="row in rows" :key="row.id" class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-medium" :class="LEVEL[row.level]?.cls">{{ LEVEL[row.level]?.label }} · {{ row.score }}</span>
                            <span class="text-xs text-zinc-500">{{ STATUS[row.status] }} · sugestão: {{ ACTION[row.suggested_action] }}</span>
                        </div>
                        <p class="mt-1 font-medium">
                            <Link :href="subjectLink(row)" class="hover:underline">{{ row.subject_type === 'withdrawal' ? 'Saque' : 'Pedido' }} #{{ row.subject_id }}</Link>
                            <template v-if="row.tenant_id"> · <Link :href="`/plataforma/usuarios/${row.tenant_id}`" class="hover:underline">{{ row.tenant_name || `Seller #${row.tenant_id}` }}</Link></template>
                        </p>
                        <ul class="mt-1 space-y-0.5 text-xs text-zinc-600 dark:text-zinc-400">
                            <li v-for="s in row.signals" :key="s.code">+{{ s.weight }} — {{ s.label }} ({{ s.detail }})</li>
                        </ul>
                        <p v-if="row.review_note" class="mt-1 whitespace-pre-line text-xs text-zinc-500">{{ row.review_note }}</p>
                    </div>
                    <div class="text-right">
                        <p class="font-semibold tabular-nums">{{ brl(row.amount_cents) }}</p>
                        <div class="mt-2 flex flex-wrap justify-end gap-1.5">
                            <Button v-if="row.status === 'open'" size="sm" variant="outline" @click="open(row, 'reviewed')">Marcar revisado</Button>
                            <Button v-if="row.status === 'open'" size="sm" variant="ghost" @click="open(row, 'dismissed')">Descartar</Button>
                            <Button v-else size="sm" variant="ghost" @click="open(row, 'open')">Reabrir</Button>
                        </div>
                    </div>
                </div>
            </li>
        </ul>

        <div v-if="editing" class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-4 sm:items-center" @click.self="editing = null">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 dark:bg-zinc-900">
                <p class="font-semibold">{{ editing.subject_type === 'withdrawal' ? 'Saque' : 'Pedido' }} #{{ editing.subject_id }} → {{ STATUS[editStatus] }}</p>
                <textarea v-model="note" rows="3" maxlength="2000" placeholder="O que foi verificado (obrigatório)" class="mt-3 w-full rounded-xl border border-zinc-200 bg-white p-3 text-sm dark:border-zinc-700 dark:bg-zinc-800" />
                <div class="mt-3 flex justify-end gap-2">
                    <Button variant="ghost" @click="editing = null">Cancelar</Button>
                    <Button :disabled="saving || note.trim().length < 5" @click="save">Salvar</Button>
                </div>
            </div>
        </div>
    </div>
</template>
