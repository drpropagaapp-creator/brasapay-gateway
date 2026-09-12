<script setup>
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import {
    Banknote,
    ChevronDown,
    ChevronUp,
    CircleDollarSign,
    Download,
    FileSpreadsheet,
    Receipt,
    Search,
    ShoppingCart,
} from 'lucide-vue-next';

defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    period: { type: String, default: 'mes' },
    period_label: { type: String, default: '' },
    start: { type: String, default: '' },
    end: { type: String, default: '' },
    year: { type: Number, default: null },
    month: { type: Number, default: null },
    from: { type: String, default: null },
    to: { type: String, default: null },
    q: { type: String, default: '' },
    sort_by: { type: String, default: 'total_fees' },
    sort_direction: { type: String, default: 'desc' },
    per_page: { type: Number, default: 25 },
    cards: {
        type: Object,
        default: () => ({
            sale_fees: 0,
            withdrawal_fees: 0,
            total_fees: 0,
            sales_count: 0,
            volume: 0,
            withdrawals_count: 0,
        }),
    },
    totals: { type: Object, default: () => ({}) },
    sellers: { type: [Object, Array], default: () => ({ data: [], links: [], total: 0 }) },
    year_options: { type: Array, default: () => [] },
    month_options: { type: Array, default: () => [] },
});

const searchQ = ref(props.q ?? '');
const perPage = ref(Number(props.per_page) || 25);
const monthValue = ref(Number(props.month) || new Date().getMonth() + 1);
const yearValue = ref(Number(props.year) || new Date().getFullYear());
const fromDate = ref(props.from ?? '');
const toDate = ref(props.to ?? '');
const exportOpen = ref(false);

watch(() => props.q, (value) => { searchQ.value = value ?? ''; });
watch(() => props.per_page, (value) => { perPage.value = Number(value) || 25; });
watch(() => props.month, (value) => { monthValue.value = Number(value) || monthValue.value; });
watch(() => props.year, (value) => { yearValue.value = Number(value) || yearValue.value; });
watch(() => props.from, (value) => { fromDate.value = value ?? ''; });
watch(() => props.to, (value) => { toDate.value = value ?? ''; });

const periodOptions = [
    { value: 'hoje', label: 'Hoje' },
    { value: 'ontem', label: 'Ontem' },
    { value: '7dias', label: 'Últimos 7 dias' },
    { value: 'mes', label: 'Mês' },
    { value: 'ano', label: 'Ano' },
    { value: 'personalizado', label: 'Personalizado' },
];

const sellersList = computed(() => (Array.isArray(props.sellers?.data) ? props.sellers.data : []));
const paginationLinks = computed(() => (Array.isArray(props.sellers?.links) ? props.sellers.links : []));
const sellersMeta = computed(() => {
    if (props.sellers && typeof props.sellers === 'object' && !Array.isArray(props.sellers)) {
        return props.sellers;
    }
    return { total: sellersList.value.length, from: null, to: null };
});

function listingQuery(overrides = {}) {
    const period = overrides.period !== undefined ? overrides.period : props.period;
    const query = {
        period,
        q: overrides.q !== undefined ? overrides.q : (searchQ.value.trim() || undefined),
        sort_by: overrides.sort_by !== undefined ? overrides.sort_by : props.sort_by,
        sort_direction: overrides.sort_direction !== undefined ? overrides.sort_direction : props.sort_direction,
        per_page: Number(overrides.per_page ?? perPage.value) || 25,
    };
    if (period === 'mes') {
        query.month = Number(overrides.month ?? monthValue.value);
        query.year = Number(overrides.year ?? yearValue.value);
    } else if (period === 'ano') {
        query.year = Number(overrides.year ?? yearValue.value);
    } else if (period === 'personalizado') {
        query.from = overrides.from !== undefined ? overrides.from : fromDate.value;
        query.to = overrides.to !== undefined ? overrides.to : toDate.value;
    }
    const currentPage = Object.prototype.hasOwnProperty.call(overrides, 'page')
        ? overrides.page
        : props.sellers?.current_page;
    if (Number(currentPage) > 1) query.page = Number(currentPage);
    return query;
}

function visit(overrides = {}) {
    router.get('/plataforma/fiscal', listingQuery(overrides), { preserveState: true, replace: true });
}

function setPeriod(value) {
    visit({ period: value, page: 1 });
}

function applySearch() {
    visit({ page: 1, q: searchQ.value.trim() || undefined });
}

function changePerPage() {
    visit({ per_page: perPage.value, page: 1 });
}

function toggleSort(column) {
    const direction = props.sort_by === column && props.sort_direction === 'desc' ? 'asc' : 'desc';
    visit({ sort_by: column, sort_direction: direction, page: 1 });
}

function sortIndicator(column) {
    return props.sort_by === column ? props.sort_direction : null;
}

function exportHref(format) {
    const params = new URLSearchParams();
    const query = listingQuery({ page: undefined });
    Object.entries(query).forEach(([key, value]) => {
        if (value !== undefined && value !== null && value !== '') {
            params.set(key, String(value));
        }
    });
    return `/plataforma/fiscal/export.${format}?${params.toString()}`;
}

function formatBRL(value) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value) || 0);
}

function formatNumber(value) {
    return new Intl.NumberFormat('pt-BR').format(Number(value) || 0);
}

const columns = [
    { key: 'name', label: 'Infoprodutor', align: 'left' },
    { key: 'document', label: 'CPF/CNPJ', align: 'left', sortable: false },
    { key: 'sales_count', label: 'Vendas', align: 'right' },
    { key: 'volume', label: 'Volume Vendido', align: 'right' },
    { key: 'sale_fees', label: 'Taxas de Venda', align: 'right' },
    { key: 'withdrawals_count', label: 'Saques', align: 'right' },
    { key: 'withdrawal_fees', label: 'Taxas de Saque', align: 'right' },
    { key: 'total_fees', label: 'Total em Taxas', align: 'right' },
];
</script>

<template>
    <div class="min-w-0 space-y-6 overflow-x-hidden">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <h1 class="text-xl font-semibold text-zinc-900 dark:text-white">Fiscal</h1>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                    Consolidação das taxas geradas pelos infoprodutores no período selecionado.
                </p>
            </div>
            <div class="relative">
                <button
                    type="button"
                    class="inline-flex h-10 items-center gap-2 rounded-xl border border-zinc-200 bg-white px-4 text-sm font-medium text-zinc-800 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white"
                    @click="exportOpen = !exportOpen"
                >
                    <Download class="h-4 w-4" />
                    Exportar Relatório
                    <ChevronDown class="h-4 w-4 text-zinc-400" />
                </button>
                <div
                    v-if="exportOpen"
                    class="absolute right-0 z-20 mt-1 w-44 overflow-hidden rounded-xl border border-zinc-200 bg-white py-1 shadow-xl dark:border-zinc-600 dark:bg-zinc-800"
                >
                    <a :href="exportHref('csv')" class="flex items-center gap-2 px-3 py-2 text-sm hover:bg-zinc-50 dark:hover:bg-zinc-700" @click="exportOpen = false">
                        <Download class="h-4 w-4" /> CSV
                    </a>
                    <a :href="exportHref('xlsx')" class="flex items-center gap-2 px-3 py-2 text-sm hover:bg-zinc-50 dark:hover:bg-zinc-700" @click="exportOpen = false">
                        <FileSpreadsheet class="h-4 w-4" /> Excel / XLSX
                    </a>
                </div>
            </div>
        </div>

        <div class="flex flex-col gap-3">
            <nav class="flex flex-wrap items-center gap-1" aria-label="Período">
                <button
                    v-for="opt in periodOptions"
                    :key="opt.value"
                    type="button"
                    :aria-current="period === opt.value ? 'true' : undefined"
                    class="rounded-lg px-3 py-2 text-sm font-medium transition-colors"
                    :class="period === opt.value
                        ? 'bg-[var(--color-primary)] text-white'
                        : 'text-zinc-600 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200'"
                    @click="setPeriod(opt.value)"
                >
                    {{ opt.label }}
                </button>
            </nav>

            <div v-if="period === 'mes'" class="flex flex-wrap items-center gap-2">
                <select
                    v-model.number="monthValue"
                    class="rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                    @change="visit({ period: 'mes', month: monthValue, year: yearValue, page: 1 })"
                >
                    <option v-for="opt in month_options" :key="opt.value" :value="opt.value">{{ opt.label }}</option>
                </select>
                <select
                    v-model.number="yearValue"
                    class="rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                    @change="visit({ period: 'mes', month: monthValue, year: yearValue, page: 1 })"
                >
                    <option v-for="y in year_options" :key="y" :value="y">{{ y }}</option>
                </select>
            </div>

            <div v-else-if="period === 'ano'" class="flex flex-wrap items-center gap-2">
                <select
                    v-model.number="yearValue"
                    class="rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                    @change="visit({ period: 'ano', year: yearValue, page: 1 })"
                >
                    <option v-for="y in year_options" :key="y" :value="y">{{ y }}</option>
                </select>
            </div>

            <form v-else-if="period === 'personalizado'" class="flex flex-wrap items-end gap-2" @submit.prevent="visit({ period: 'personalizado', from: fromDate, to: toDate, page: 1 })">
                <label class="text-sm text-zinc-600 dark:text-zinc-400">
                    Data inicial
                    <input v-model="fromDate" type="date" class="mt-1 block rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900" />
                </label>
                <label class="text-sm text-zinc-600 dark:text-zinc-400">
                    Data final
                    <input v-model="toDate" type="date" class="mt-1 block rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900" />
                </label>
                <button type="submit" class="h-10 rounded-xl bg-[var(--color-primary)] px-4 text-sm font-medium text-white">Aplicar</button>
            </form>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <div class="rounded-2xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-700 dark:bg-zinc-900/40">
                <div class="flex items-center gap-2 text-sm font-medium text-zinc-600 dark:text-zinc-400">
                    <Receipt class="h-4 w-4 text-[var(--color-primary)]" />
                    Taxas de Venda
                </div>
                <p class="mt-2 text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ formatBRL(cards.sale_fees) }}</p>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-700 dark:bg-zinc-900/40">
                <div class="flex items-center gap-2 text-sm font-medium text-zinc-600 dark:text-zinc-400">
                    <Banknote class="h-4 w-4 text-[var(--color-primary)]" />
                    Taxas de Saque
                </div>
                <p class="mt-2 text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ formatBRL(cards.withdrawal_fees) }}</p>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-700 dark:bg-zinc-900/40">
                <div class="flex items-center gap-2 text-sm font-medium text-zinc-600 dark:text-zinc-400">
                    <CircleDollarSign class="h-4 w-4 text-[var(--color-primary)]" />
                    Total em Taxas
                </div>
                <p class="mt-2 text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ formatBRL(cards.total_fees) }}</p>
            </div>
            <div class="rounded-2xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-700 dark:bg-zinc-900/40">
                <div class="flex items-center gap-2 text-sm font-medium text-zinc-600 dark:text-zinc-400">
                    <ShoppingCart class="h-4 w-4 text-[var(--color-primary)]" />
                    Quantidade de Vendas
                </div>
                <p class="mt-2 text-2xl font-bold tabular-nums text-zinc-900 dark:text-white">{{ formatNumber(cards.sales_count) }}</p>
            </div>
        </div>

        <section class="space-y-3">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Relatório por Infoprodutor</h2>
                <form class="flex flex-wrap items-center gap-2" @submit.prevent="applySearch">
                    <div class="relative min-w-[220px] flex-1">
                        <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-zinc-400" />
                        <input
                            v-model="searchQ"
                            type="search"
                            placeholder="Buscar infoprodutor..."
                            class="w-full rounded-xl border border-zinc-300 bg-white py-2 pl-9 pr-3 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                        />
                    </div>
                    <button type="submit" class="rounded-xl bg-[var(--color-primary)] px-4 py-2 text-sm font-medium text-white">Pesquisar</button>
                    <label class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-400">
                        <span>Por página</span>
                        <select v-model="perPage" class="rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900" @change="changePerPage">
                            <option :value="25">25</option>
                            <option :value="50">50</option>
                            <option :value="100">100</option>
                        </select>
                    </label>
                </form>
            </div>

            <div class="overflow-x-auto rounded-2xl border border-zinc-200 dark:border-zinc-700">
                <table class="min-w-full text-sm">
                    <thead class="bg-zinc-50 text-left text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:bg-zinc-900/60 dark:text-zinc-400">
                        <tr>
                            <th
                                v-for="col in columns"
                                :key="col.key"
                                class="px-4 py-3"
                                :class="col.align === 'right' ? 'text-right' : 'text-left'"
                            >
                                <button
                                    v-if="col.sortable !== false"
                                    type="button"
                                    class="inline-flex items-center gap-1"
                                    @click="toggleSort(col.key)"
                                >
                                    {{ col.label }}
                                    <ChevronUp v-if="sortIndicator(col.key) === 'asc'" class="h-3 w-3" />
                                    <ChevronDown v-else-if="sortIndicator(col.key) === 'desc'" class="h-3 w-3" />
                                </button>
                                <span v-else>{{ col.label }}</span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!sellersList.length">
                            <td colspan="8" class="px-4 py-8 text-center text-zinc-500">Nenhuma taxa no período selecionado.</td>
                        </tr>
                        <tr
                            v-for="row in sellersList"
                            :key="row.tenant_id"
                            class="border-t border-zinc-100 dark:border-zinc-800"
                        >
                            <td class="px-4 py-3">
                                <p class="font-medium text-zinc-900 dark:text-white">{{ row.name }}</p>
                                <p v-if="row.email" class="text-xs text-zinc-500">{{ row.email }}</p>
                            </td>
                            <td class="px-4 py-3 tabular-nums text-zinc-700 dark:text-zinc-300">{{ row.document || '—' }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ formatNumber(row.sales_count) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ formatBRL(row.volume) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ formatBRL(row.sale_fees) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ formatNumber(row.withdrawals_count) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ formatBRL(row.withdrawal_fees) }}</td>
                            <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ formatBRL(row.total_fees) }}</td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr class="border-t-2 border-zinc-200 bg-zinc-50 font-semibold dark:border-zinc-600 dark:bg-zinc-900/80">
                            <td class="px-4 py-3" colspan="2">TOTAL DO PERÍODO</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ formatNumber(totals.sales_count ?? cards.sales_count) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ formatBRL(totals.volume ?? cards.volume) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ formatBRL(totals.sale_fees ?? cards.sale_fees) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ formatNumber(totals.withdrawals_count ?? cards.withdrawals_count) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ formatBRL(totals.withdrawal_fees ?? cards.withdrawal_fees) }}</td>
                            <td class="px-4 py-3 text-right tabular-nums">{{ formatBRL(totals.total_fees ?? cards.total_fees) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <div v-if="paginationLinks.length > 3" class="flex flex-wrap items-center justify-between gap-2 text-sm text-zinc-500">
                <p>
                    {{ sellersMeta.from ?? 0 }}–{{ sellersMeta.to ?? 0 }} de {{ formatNumber(sellersMeta.total || 0) }}
                </p>
                <div class="flex flex-wrap gap-1">
                    <button
                        v-for="(link, idx) in paginationLinks"
                        :key="`${idx}-${link.label}`"
                        type="button"
                        class="rounded-lg px-3 py-1.5"
                        :class="link.active
                            ? 'bg-[var(--color-primary)] text-white'
                            : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800'"
                        :disabled="!link.url"
                        v-html="link.label"
                        @click="link.url && router.get(link.url, {}, { preserveState: true, replace: true })"
                    />
                </div>
            </div>
        </section>
    </div>
</template>
