<script setup>
import VueApexCharts from 'vue3-apexcharts';
import { computed } from 'vue';
import { useThemeMode } from '@/composables/useThemeMode';
import ConquistasWidget from '@/components/layout/ConquistasWidget.vue';
import PrimePeriodToolbar from '@/components/prime/PrimePeriodToolbar.vue';
import PrimePaymentMethodsCard from '@/components/prime/PrimePaymentMethodsCard.vue';
import {
    ShoppingBag,
    RotateCcw,
    Package,
    TrendingUp,
    ChevronRight,
} from 'lucide-vue-next';
import { Link } from '@inertiajs/vue3';

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

const periodDisplayLabel = computed(() => {
    const selected = props.periodOptions.find((opt) => opt.value === props.period);
    return selected?.label ?? props.periodLabel;
});

const primeChartOptions = computed(() => {
    const axisColor = isDark.value ? '#6b7488' : '#717c91';
    const gridColor = isDark.value ? '#1d222d' : '#e5e8ee';

    return {
        ...props.chartOptions,
        chart: {
            ...(props.chartOptions.chart ?? {}),
            background: 'transparent',
            foreColor: axisColor,
            toolbar: { show: false },
        },
        stroke: {
            curve: 'straight',
            width: 2,
        },
        colors: ['var(--color-primary)'],
        fill: {
            type: 'gradient',
            gradient: {
                shadeIntensity: 0.15,
                opacityFrom: 0.28,
                opacityTo: 0.0,
                stops: [0, 100],
            },
        },
        dataLabels: { enabled: false },
        grid: {
            borderColor: gridColor,
            strokeDashArray: 3,
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
    <div class="space-y-5">
        <div v-if="hasAchievementsProgress" class="lg:hidden">
            <ConquistasWidget variant="dashboard" />
        </div>

        <PrimePeriodToolbar
            :period="period"
            :period-options="periodOptions"
            :values-visible="valuesVisible"
            :period-label="periodLabel"
            :hide-values-label="hideValuesLabel"
            :show-values-label="showValuesLabel"
            @update:period="emit('update:period', $event)"
            @toggle-values="emit('toggle-values')"
        />

        <!-- Faixa de receita estilo extrato -->
        <section class="prime-ledger" :aria-label="labels.totalSales">
            <div class="flex flex-col lg:flex-row lg:items-stretch">
                <div class="prime-ledger-main flex-1 px-6 py-5 lg:py-6">
                    <p class="prime-kpi-label">
                        {{ labels.totalSales }} · {{ periodDisplayLabel }}
                    </p>
                    <p class="prime-hero-value mt-2 text-[32px] font-bold leading-none sm:text-[36px]">
                        {{ displayCurrency(vendas_totais) }}
                    </p>
                    <p class="prime-fg-muted mt-3 flex items-center gap-2 text-[13px]">
                        <span
                            class="inline-block h-1.5 w-1.5 rounded-full bg-[var(--prime-warning)]"
                            aria-hidden="true"
                        />
                        {{ labels.pendingSales }}:
                        <span class="prime-num font-semibold">{{ displayCurrency(vendas_pendentes) }}</span>
                    </p>
                </div>

                <div
                    class="prime-ledger-divider grid grid-cols-3 gap-px border-t lg:w-[46%] lg:grid-cols-3 lg:border-l lg:border-t-0"
                >
                    <div class="px-5 py-4 lg:flex lg:flex-col lg:justify-center">
                        <p class="prime-kpi-label">{{ labels.salesCount }}</p>
                        <p class="prime-kpi-value mt-1.5 text-xl font-bold leading-none">
                            {{ displayNumber(quantidade_vendas) }}
                        </p>
                    </div>
                    <div class="prime-ledger-divider border-l px-5 py-4 lg:flex lg:flex-col lg:justify-center">
                        <p class="prime-kpi-label">{{ labels.avgTicket }}</p>
                        <p class="prime-kpi-value mt-1.5 text-xl font-bold leading-none">
                            {{ displayCurrency(ticket_medio) }}
                        </p>
                    </div>
                    <div class="prime-ledger-divider border-l px-5 py-4 lg:flex lg:flex-col lg:justify-center">
                        <p class="prime-kpi-label">{{ labels.conversionRate }}</p>
                        <p class="prime-kpi-value mt-1.5 text-xl font-bold leading-none">
                            {{ conversaoFormatted }}
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <div class="grid gap-4 lg:grid-cols-3">
            <!-- Desempenho de vendas -->
            <div class="prime-card p-5 pb-1 lg:col-span-2">
                <h2 class="prime-section-title mb-4 flex items-center gap-2">
                    <TrendingUp class="h-4 w-4 text-[var(--color-primary)]" aria-hidden="true" />
                    {{ labels.salesPerformance }}
                </h2>
                <div class="min-h-[320px]">
                    <VueApexCharts
                        v-if="grafico_vendas.length"
                        :key="isDark ? 'prime-chart-dark' : 'prime-chart-light'"
                        type="area"
                        height="320"
                        :options="primeChartOptions"
                        :series="chartSeries"
                    />
                    <p v-else class="prime-fg-muted flex h-[320px] items-center justify-center text-sm">
                        {{ labels.noSalesData }}
                    </p>
                </div>
            </div>

            <div class="flex flex-col gap-4">
                <PrimePaymentMethodsCard
                    class="flex-1"
                    :formas_pagamento="formas_pagamento"
                    :payment-methods-label="labels.paymentMethods"
                    :no-payments-label="labels.noPayments"
                    :display-currency="displayCurrency"
                    :display-number="displayNumber"
                />

                <!-- Pontos de atenção -->
                <div class="prime-card overflow-hidden">
                    <Link href="/relatorios" class="prime-attention-row group">
                        <span class="prime-attention-icon prime-attention-icon--warning">
                            <ShoppingBag class="h-4 w-4" aria-hidden="true" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="prime-fg-muted block text-[13px]">{{ labels.cartAbandonment }}</span>
                            <span class="prime-card-value prime-num block text-base font-bold leading-tight">
                                {{ displayNumber(abandono_carrinho) }}
                            </span>
                        </span>
                        <ChevronRight
                            class="prime-fg-subtle h-4 w-4 shrink-0 transition group-hover:translate-x-0.5"
                            aria-hidden="true"
                        />
                    </Link>
                    <Link href="/vendas/reembolsos" class="prime-attention-row group">
                        <span class="prime-attention-icon prime-attention-icon--negative">
                            <RotateCcw class="h-4 w-4" aria-hidden="true" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="prime-fg-muted block text-[13px]">{{ labels.refunds }}</span>
                            <span class="prime-card-value prime-num block text-base font-bold leading-tight">
                                {{ displayCurrency(reembolsos_total) }}
                                <span class="prime-fg-subtle text-xs font-normal">
                                    · {{ displayNumber(reembolsos_count) }} {{ labels.ordersCount }}
                                </span>
                            </span>
                        </span>
                        <ChevronRight
                            class="prime-fg-subtle h-4 w-4 shrink-0 transition group-hover:translate-x-0.5"
                            aria-hidden="true"
                        />
                    </Link>
                    <Link href="/produtos" class="prime-attention-row group">
                        <span class="prime-attention-icon">
                            <Package class="h-4 w-4" aria-hidden="true" />
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="prime-fg-muted block text-[13px]">{{ labels.products }}</span>
                            <span class="prime-card-value prime-num block text-base font-bold leading-tight">
                                {{ displayNumber(quantidade_produtos) }}
                            </span>
                        </span>
                        <ChevronRight
                            class="prime-fg-subtle h-4 w-4 shrink-0 transition group-hover:translate-x-0.5"
                            aria-hidden="true"
                        />
                    </Link>
                </div>
            </div>
        </div>
    </div>
</template>
