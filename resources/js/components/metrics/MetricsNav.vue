<script setup>
import { Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    tab: { type: String, default: 'dashboard' },
    period: { type: String, default: 'hoje' },
    date_from: { type: String, default: null },
    date_to: { type: String, default: null },
    filters: { type: Object, default: () => ({}) },
    basePath: { type: String, default: '/metricas' },
    sellers: { type: Array, default: () => [] },
    sellerId: { type: [Number, String], default: null },
});

const tabs = computed(() => {
    const all = [
        { id: 'dashboard', label: 'Dashboard', href: props.basePath },
        { id: 'origins', label: 'Origem das vendas', href: `${props.basePath}/origem` },
        { id: 'funnel', label: 'Funil', href: `${props.basePath}/funil` },
        { id: 'clicks', label: 'Cliques', href: `${props.basePath}/cliques` },
        { id: 'map', label: 'Mapa', href: `${props.basePath}/mapa` },
    ];
    const showMap = props.basePath === '/metricas' || props.basePath.startsWith('/plataforma/metricas');

    return showMap ? all : all.filter((t) => t.id !== 'map');
});

const periodOptions = [
    { value: 'hoje', label: 'Hoje' },
    { value: 'ontem', label: 'Ontem' },
    { value: '7dias', label: '7 dias' },
    { value: '30dias', label: '30 dias' },
    { value: 'mes', label: 'Mês atual' },
    { value: 'mes_anterior', label: 'Mês anterior' },
    { value: 'personalizado', label: 'Personalizado' },
];

const isPlatform = computed(() => props.basePath.startsWith('/plataforma'));

const queryBase = computed(() => {
    const q = { period: props.period, ...props.filters };
    if (props.period === 'personalizado') {
        if (props.date_from) q.date_from = props.date_from;
        if (props.date_to) q.date_to = props.date_to;
    }
    if (isPlatform.value && props.sellerId) {
        q.seller_id = props.sellerId;
    }
    Object.keys(q).forEach((k) => {
        if (q[k] === null || q[k] === undefined || q[k] === '') delete q[k];
    });
    return q;
});

function currentPath() {
    return tabs.value.find((t) => t.id === props.tab)?.href || props.basePath;
}

function tabHref(href) {
    const params = new URLSearchParams();
    Object.entries(queryBase.value).forEach(([k, v]) => {
        if (v !== undefined && v !== null && v !== '') params.set(k, String(v));
    });
    const qs = params.toString();
    return qs ? `${href}?${qs}` : href;
}

function setPeriod(value) {
    const path = currentPath();
    const q = { ...queryBase.value, period: value };
    if (value === 'personalizado') {
        const today = new Date();
        const first = new Date(today.getFullYear(), today.getMonth(), 1);
        const fmt = (d) => d.toISOString().slice(0, 10);
        q.date_from = props.date_from || fmt(first);
        q.date_to = props.date_to || fmt(today);
    } else {
        delete q.date_from;
        delete q.date_to;
    }
    router.get(path, q, { preserveState: false });
}

function setSeller(sellerId) {
    const path = currentPath();
    const q = { ...queryBase.value };
    if (sellerId) q.seller_id = sellerId;
    else delete q.seller_id;
    router.get(path, q, { preserveState: false });
}

defineExpose({ queryBase, setPeriod, setSeller, periodOptions });
</script>

<template>
    <div class="flex flex-col gap-4">
        <nav class="pn-tabs self-start" aria-label="Métricas">
            <Link
                v-for="t in tabs"
                :key="t.id"
                :href="tabHref(t.href)"
                class="pn-tab inline-flex items-center no-underline hover:no-underline"
                :class="tab === t.id ? 'pn-tab--active' : ''"
            >
                {{ t.label }}
            </Link>
        </nav>

        <div class="flex flex-wrap items-center gap-3">
            <div class="pn-seg max-w-full flex-wrap" role="group" aria-label="Período">
                <button
                    v-for="p in periodOptions"
                    :key="p.value"
                    type="button"
                    class="pn-tab pn-keep-shape"
                    :class="period === p.value ? 'pn-tab--active' : ''"
                    @click="setPeriod(p.value)"
                >
                    {{ p.label }}
                </button>
            </div>

            <select
                v-if="isPlatform && sellers.length"
                class="h-9 rounded-lg border border-zinc-200 bg-white px-3 text-xs dark:border-zinc-700 dark:bg-zinc-900"
                :value="sellerId || ''"
                @change="setSeller($event.target.value || null)"
            >
                <option value="">Todos os infoprodutores</option>
                <option v-for="s in sellers" :key="s.id" :value="s.id">{{ s.label }}</option>
            </select>

            <slot name="filters" />
        </div>
    </div>
</template>
