<script setup>
import VueApexCharts from 'vue3-apexcharts';
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { useThemeMode } from '@/composables/useThemeMode';
import ConquistasWidget from '@/components/layout/ConquistasWidget.vue';
import {
    Eye,
    EyeOff,
    Calendar,
    ChevronRight,
    ShoppingBag,
    RotateCcw,
    Package,
    CreditCard,
} from 'lucide-vue-next';

const props = defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, required: true },
    hasAchievementsProgress: { type: Boolean, default: false },
    period: { type: String, required: true },
    periodOptions: { type: Array, required: true },
    valuesVisible: { type: Boolean, required: true },
    periodLabel: { type: String, default: 'Período' },
    hideValuesLabel: { type: String, default: 'Ocultar valores' },
    showValuesLabel: { type: String, default: 'Mostrar valores' },
    vendas_totais: { type: Number, default: 0 },
    vendas_pendentes: { type: Number, default: 0 },
    quantidade_vendas: { type: Number, default: 0 },
    ticket_medio: { type: Number, default: 0 },
    formas_pagamento: { type: Array, default: () => [] },
    taxa_conversao: { type: Number, default: 0 },
    abandono_carrinho: { type: Number, default: 0 },
    reembolsos_count: { type: Number, default: 0 },
    reembolsos_total: { type: Number, default: 0 },
    quantidade_produtos: { type: Number, default: 0 },
    grafico_vendas: { type: Array, default: () => [] },
    chartOptions: { type: Object, required: true },
    chartSeries: { type: Array, required: true },
    labels: { type: Object, required: true },
    displayCurrency: { type: Function, required: true },
    displayNumber: { type: Function, required: true },
});

const emit = defineEmits(['update:period', 'toggle-values']);

const { isDark } = useThemeMode();

const conversaoFormatted = computed(() =>
    props.valuesVisible ? `${props.taxa_conversao}%` : '—'
);

function formatShortDate(date) {
    return date.toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

const periodRangeLabel = computed(() => {
    const now = new Date();
    const start = new Date(now);

    switch (props.period) {
        case 'hoje':
            return formatShortDate(now);
        case 'ontem':
            start.setDate(start.getDate() - 1);
            return formatShortDate(start);
        case '7dias':
            start.setDate(start.getDate() - 6);
            return `${formatShortDate(start)} – ${formatShortDate(now)}`;
        case 'mes':
            start.setDate(1);
            return `${formatShortDate(start)} – ${formatShortDate(now)}`;
        case 'ano':
            start.setMonth(0, 1);
            return `${formatShortDate(start)} – ${formatShortDate(now)}`;
        default:
            return null;
    }
});

const totalPagamentos = computed(() =>
    props.formas_pagamento.reduce((sum, fp) => sum + (Number(fp.total) || 0), 0)
);

function percentOfTotal(fp) {
    if (!totalPagamentos.value) return 0;
    return Math.round((Number(fp.total) / totalPagamentos.value) * 100);
}

const studioChartOptions = computed(() => {
    const axisColor = isDark.value ? '#6f7680' : '#8a919a';
    const gridColor = isDark.value ? '#262b31' : '#e8eaed';

    return {
        ...props.chartOptions,
        chart: {
            ...(props.chartOptions.chart ?? {}),
            background: 'transparent',
            foreColor: axisColor,
            toolbar: { show: false },
        },
        stroke: {
            curve: 'smooth',
            width: 2.5,
        },
        colors: ['var(--color-primary)'],
        fill: {
            type: 'gradient',
            gradient: {
                shadeIntensity: 0.2,
                opacityFrom: 0.25,
                opacityTo: 0.0,
                stops: [0, 100],
            },
        },
        dataLabels: { enabled: false },
        grid: {
            borderColor: gridColor,
            strokeDashArray: 4,
            xaxis: { lines: { show: false } },
            yaxis: { lines: { show: true } },
        },
        xaxis: {
            ...(props.chartOptions.xaxis ?? {}),
            labels: { style: { colors: axisColor, fontSize: '11px' } },
            axisBorder: { show: false },
            axisTicks: { show: false },
        },
        yaxis: {
            ...(props.chartOptions.yaxis ?? {}),
            labels: {
                style: { colors: axisColor, fontSize: '11px' },
                formatter: (v) => props.displayCurrency(v),
            },
        },
        tooltip: { theme: isDark.value ? 'dark' : 'light' },
        markers: {
            size: 0,
            strokeWidth: 2,
            hover: { size: 5 },
        },
    };
});
</script>

<template>
    <div class="space-y-6">
        <div v-if="hasAchievementsProgress" class="lg:hidden">
            <ConquistasWidget variant="dashboard" />
        </div>

        <!-- Banda da carteira -->
        <section class="studio-band" :aria-label="labels.totalSales">
            <div class="mb-4 flex items-center justify-end">
                <button
                    type="button"
                    :aria-label="valuesVisible ? hideValuesLabel : showValuesLabel"
                    class="studio-icon-btn flex h-10 w-10 shrink-0 items-center justify-center rounded-xl bg-[var(--studio-panel)] transition-colors"
                    @click="emit('toggle-values')"
                >
                    <Eye v-if="valuesVisible" class="h-4 w-4" aria-hidden="true" />
                    <EyeOff v-else class="h-4 w-4" aria-hidden="true" />
                </button>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="studio-wallet-card">
                    <p class="studio-wallet-label">{{ labels.totalSales }}</p>
                    <p class="studio-wallet-value">{{ displayCurrency(vendas_totais) }}</p>
                    <Link href="/vendas" class="studio-wallet-link">
                        Ver vendas
                        <ChevronRight class="h-4 w-4" aria-hidden="true" />
                    </Link>
                </div>
                <div class="studio-wallet-card">
                    <p class="studio-wallet-label">{{ labels.pendingSales }}</p>
                    <p class="studio-wallet-value">{{ displayCurrency(vendas_pendentes) }}</p>
                    <Link href="/relatorios" class="studio-wallet-link">
                        Ver relatórios
                        <ChevronRight class="h-4 w-4" aria-hidden="true" />
                    </Link>
                </div>
            </div>
        </section>

        <!-- Minhas vendas -->
        <section class="space-y-5">
            <h2 class="studio-fg text-xl font-bold tracking-tight">Minhas vendas</h2>

            <nav class="studio-tabs no-scrollbar" :aria-label="periodLabel">
                <button
                    v-for="opt in periodOptions"
                    :key="opt.value"
                    type="button"
                    :aria-current="period === opt.value ? 'true' : undefined"
                    class="studio-tab"
                    :class="period === opt.value ? 'studio-tab-active' : ''"
                    @click="emit('update:period', opt.value)"
                >
                    {{ opt.label }}
                </button>
            </nav>

            <p v-if="periodRangeLabel" class="studio-fg-muted flex items-center gap-2 text-[13px]">
                <Calendar class="h-4 w-4" aria-hidden="true" />
                {{ periodLabel }}: <span class="font-medium">{{ periodRangeLabel }}</span>
            </p>

            <div class="grid gap-6 sm:grid-cols-3">
                <div class="studio-stat studio-stat--primary">
                    <p class="studio-stat-label">{{ labels.salesCount }}</p>
                    <p class="studio-stat-value">{{ displayNumber(quantidade_vendas) }}</p>
                </div>
                <div class="studio-stat studio-stat--positive">
                    <p class="studio-stat-label">{{ labels.avgTicket }}</p>
                    <p class="studio-stat-value">{{ displayCurrency(ticket_medio) }}</p>
                </div>
                <div class="studio-stat studio-stat--info">
                    <p class="studio-stat-label">{{ labels.conversionRate }}</p>
                    <p class="studio-stat-value">{{ conversaoFormatted }}</p>
                </div>
            </div>
        </section>

        <!-- Desempenho de vendas -->
        <section class="studio-card p-5 pb-1">
            <h2 class="studio-section-title mb-4">{{ labels.salesPerformance }}</h2>
            <div class="min-h-[300px]">
                <VueApexCharts
                    v-if="grafico_vendas.length"
                    :key="isDark ? 'studio-chart-dark' : 'studio-chart-light'"
                    type="area"
                    height="300"
                    :options="studioChartOptions"
                    :series="chartSeries"
                />
                <p v-else class="studio-fg-muted flex h-[300px] items-center justify-center text-sm">
                    {{ labels.noSalesData }}
                </p>
            </div>
        </section>

        <div class="grid gap-4 lg:grid-cols-2">
            <!-- Formas de pagamento -->
            <div class="studio-card flex flex-col p-5">
                <h2 class="studio-section-title mb-4 flex items-center gap-2">
                    <CreditCard class="h-4 w-4 text-[var(--color-primary)]" aria-hidden="true" />
                    {{ labels.paymentMethods }}
                </h2>
                <ul class="space-y-4">
                    <li v-for="fp in formas_pagamento" :key="fp.metodo">
                        <div class="mb-1.5 flex items-baseline justify-between gap-2">
                            <span class="studio-fg text-sm font-medium">{{ fp.label }}</span>
                            <span class="studio-card-value text-sm font-semibold">
                                {{ displayCurrency(fp.total) }}
                                <span class="studio-fg-subtle ml-1 text-xs font-normal">{{ percentOfTotal(fp) }}%</span>
                            </span>
                        </div>
                        <div class="studio-progress-track">
                            <div
                                class="studio-progress-fill transition-all duration-500"
                                :style="{ width: `${percentOfTotal(fp)}%` }"
                            />
                        </div>
                        <p class="studio-fg-subtle mt-1 text-[11px]">
                            {{ displayNumber(fp.quantidade) }} transação(ões)
                        </p>
                    </li>
                    <li v-if="!formas_pagamento.length" class="studio-fg-muted py-4 text-center text-sm">
                        {{ labels.noPayments }}
                    </li>
                </ul>
                <div v-if="formas_pagamento.length" class="studio-divider mt-auto flex items-baseline justify-between border-t pt-4">
                    <span class="studio-fg-muted text-sm">Total no período</span>
                    <span class="studio-card-value text-base font-bold">
                        {{ displayCurrency(totalPagamentos) }}
                    </span>
                </div>
            </div>

            <!-- Pontos de atenção -->
            <div class="studio-card self-start overflow-hidden">
                <Link href="/relatorios" class="studio-attention-row group">
                    <span class="studio-attention-icon studio-attention-icon--warning">
                        <ShoppingBag class="h-4 w-4" aria-hidden="true" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="studio-fg-muted block text-[13px]">{{ labels.cartAbandonment }}</span>
                        <span class="studio-card-value block text-base font-bold leading-tight">
                            {{ displayNumber(abandono_carrinho) }}
                        </span>
                    </span>
                    <ChevronRight
                        class="studio-fg-subtle h-4 w-4 shrink-0 transition group-hover:translate-x-0.5"
                        aria-hidden="true"
                    />
                </Link>
                <Link href="/vendas/reembolsos" class="studio-attention-row group">
                    <span class="studio-attention-icon studio-attention-icon--negative">
                        <RotateCcw class="h-4 w-4" aria-hidden="true" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="studio-fg-muted block text-[13px]">{{ labels.refunds }}</span>
                        <span class="studio-card-value block text-base font-bold leading-tight">
                            {{ displayCurrency(reembolsos_total) }}
                            <span class="studio-fg-subtle text-xs font-normal">
                                · {{ displayNumber(reembolsos_count) }} {{ labels.ordersCount }}
                            </span>
                        </span>
                    </span>
                    <ChevronRight
                        class="studio-fg-subtle h-4 w-4 shrink-0 transition group-hover:translate-x-0.5"
                        aria-hidden="true"
                    />
                </Link>
                <Link href="/produtos" class="studio-attention-row group">
                    <span class="studio-attention-icon">
                        <Package class="h-4 w-4" aria-hidden="true" />
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="studio-fg-muted block text-[13px]">{{ labels.products }}</span>
                        <span class="studio-card-value block text-base font-bold leading-tight">
                            {{ displayNumber(quantidade_produtos) }}
                        </span>
                    </span>
                    <ChevronRight
                        class="studio-fg-subtle h-4 w-4 shrink-0 transition group-hover:translate-x-0.5"
                        aria-hidden="true"
                    />
                </Link>
            </div>
        </div>
    </div>
</template>
