<script setup>
import { ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';

defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    disputes: { type: Object, required: true },
    filters: { type: Object, default: () => ({}) },
});

const filterStatus = ref(props.filters?.status ?? 'open');
const filterParty = ref(props.filters?.party ?? 'platform');
const rows = () => props.disputes?.data ?? [];

watch(
    () => props.filters,
    (f) => {
        filterStatus.value = f?.status ?? 'open';
        filterParty.value = f?.party ?? 'platform';
    },
    { deep: true }
);

function formatBRL(cents) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format((Number(cents) || 0) / 100);
}

function formatDateTime(value) {
    if (!value) return '—';
    const d = new Date(value);
    if (Number.isNaN(d.getTime())) return '—';
    const date = d.toLocaleDateString('pt-BR');
    const time = d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    return `${date} · ${time}`;
}

function partyLabel(party) {
    return party === 'platform' ? 'Plataforma' : 'API PIX';
}

function statusLabel(status) {
    const map = {
        open: 'Aberta',
        defense_submitted: 'Defesa enviada',
        resolved_won: 'Ganha',
        resolved_lost: 'Perdida',
        cancelled: 'Cancelada',
    };
    return map[status] ?? status ?? '—';
}

function statusBadgeClass(status) {
    if (status === 'open') return 'bg-orange-100 text-orange-900 dark:bg-orange-900/30 dark:text-orange-100';
    if (status === 'defense_submitted') return 'bg-amber-100 text-amber-900 dark:bg-amber-900/30 dark:text-amber-100';
    if (status === 'resolved_won') return 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-200';
    if (status === 'resolved_lost' || status === 'cancelled') return 'bg-zinc-200 text-zinc-800 dark:bg-zinc-700 dark:text-zinc-200';
    return 'bg-zinc-100 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200';
}

function applyFilters() {
    router.get(
        '/plataforma/disputas',
        { status: filterStatus.value, party: filterParty.value },
        { preserveState: true, preserveScroll: true, replace: true }
    );
}

function setStatus(status) {
    filterStatus.value = status;
    applyFilters();
}

function setParty(party) {
    filterParty.value = party;
    applyFilters();
}
</script>

<template>
    <div class="space-y-6">
        <p class="text-sm text-zinc-600 dark:text-zinc-400">
            Gestão de disputas MED: checkout e MED Zero (plataforma) vs API PIX REST (infoprodutor).
        </p>

        <div class="flex flex-wrap gap-2">
            <button
                v-for="tab in [
                    { value: 'platform', label: 'Plataforma' },
                    { value: 'tenant', label: 'API PIX' },
                ]"
                :key="tab.value"
                type="button"
                class="rounded-full px-3 py-1.5 text-sm font-medium"
                :class="filterParty === tab.value ? 'bg-[var(--color-primary)] text-white' : 'bg-zinc-100 dark:bg-zinc-800'"
                @click="setParty(tab.value)"
            >
                {{ tab.label }}
            </button>
        </div>

        <div class="flex flex-wrap gap-2">
            <button
                v-for="tab in [
                    { value: 'open', label: 'Abertas' },
                    { value: 'resolved', label: 'Resolvidas' },
                    { value: 'all', label: 'Todas' },
                ]"
                :key="tab.value"
                type="button"
                class="rounded-full px-3 py-1.5 text-sm font-medium"
                :class="filterStatus === tab.value ? 'bg-zinc-800 text-white dark:bg-zinc-200 dark:text-zinc-900' : 'bg-zinc-100 dark:bg-zinc-800'"
                @click="setStatus(tab.value)"
            >
                {{ tab.label }}
            </button>
        </div>

        <div v-if="rows().length" class="space-y-3 lg:hidden">
            <a
                v-for="d in rows()"
                :key="`mobile-${d.id}`"
                :href="`/plataforma/disputas/${d.id}`"
                class="block rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm transition hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:hover:bg-zinc-700/40"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="font-mono text-sm font-semibold text-zinc-900 dark:text-white">
                            Disputa #{{ d.id }}
                        </p>
                        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                            {{ formatDateTime(d.opened_at) }}
                        </p>
                    </div>
                    <span class="shrink-0 text-sm font-semibold tabular-nums text-zinc-900 dark:text-white">
                        {{ formatBRL(d.amount_cents) }}
                    </span>
                </div>

                <div class="mt-2">
                    <span
                        :class="['inline-flex max-w-full rounded-full px-2 py-0.5 text-[11px] font-medium leading-tight', statusBadgeClass(d.status)]"
                    >
                        {{ statusLabel(d.status) }}
                    </span>
                </div>

                <dl class="mt-4 grid grid-cols-2 gap-x-3 gap-y-3 border-t border-zinc-100 pt-3 dark:border-zinc-700">
                    <div class="min-w-0">
                        <dt class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            Responsável
                        </dt>
                        <dd class="mt-0.5 break-words text-sm text-zinc-800 dark:text-zinc-200">
                            {{ partyLabel(d.responsible_party) }}
                        </dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            Pedido
                        </dt>
                        <dd class="mt-0.5 break-words text-sm font-medium text-zinc-900 dark:text-white">
                            #{{ d.order?.id ?? '—' }}
                        </dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            Infoprodutor
                        </dt>
                        <dd class="mt-0.5 break-words text-sm font-medium text-zinc-900 dark:text-white">
                            {{ d.tenant?.name ?? '—' }}
                        </dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="text-[11px] font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            Origem
                        </dt>
                        <dd class="mt-0.5 break-words text-sm text-zinc-800 dark:text-zinc-200">
                            {{ d.order_origin ?? '—' }}
                        </dd>
                    </div>
                </dl>
            </a>
        </div>
        <div
            v-else
            class="rounded-2xl border border-dashed border-zinc-200 px-4 py-12 text-center text-sm text-zinc-500 dark:border-zinc-700 lg:hidden"
        >
            Nenhuma disputa encontrada.
        </div>

        <div class="hidden overflow-hidden rounded-2xl border border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900/60 lg:block">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-800">
                <thead class="bg-zinc-50 dark:bg-zinc-800/80">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase">ID</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Responsável</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Infoprodutor</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Pedido</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Origem</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Valor</th>
                        <th class="px-4 py-3 text-left text-xs font-semibold uppercase">Status</th>
                        <th class="px-4 py-3 text-right text-xs font-semibold uppercase">Ação</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    <tr v-for="d in rows()" :key="d.id">
                        <td class="px-4 py-3 text-sm">#{{ d.id }}</td>
                        <td class="px-4 py-3 text-sm">{{ partyLabel(d.responsible_party) }}</td>
                        <td class="px-4 py-3 text-sm">{{ d.tenant?.name ?? '—' }}</td>
                        <td class="px-4 py-3 text-sm">#{{ d.order?.id }}</td>
                        <td class="px-4 py-3 text-sm">{{ d.order_origin ?? '—' }}</td>
                        <td class="px-4 py-3 text-sm">{{ formatBRL(d.amount_cents) }}</td>
                        <td class="px-4 py-3 text-sm">
                            <span
                                :class="['inline-flex rounded-full px-2 py-0.5 text-[11px] font-medium leading-tight', statusBadgeClass(d.status)]"
                            >
                                {{ statusLabel(d.status) }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a :href="`/plataforma/disputas/${d.id}`" class="text-sm text-[var(--color-primary)] hover:underline">Ver</a>
                        </td>
                    </tr>
                    <tr v-if="rows().length === 0">
                        <td colspan="8" class="px-4 py-8 text-center text-sm text-zinc-500">Nenhuma disputa encontrada.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
