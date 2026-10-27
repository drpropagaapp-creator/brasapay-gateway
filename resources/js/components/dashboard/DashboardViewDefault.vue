<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import VueApexCharts from 'vue3-apexcharts';
import { CircleDollarSign, ShoppingCart, Receipt, RotateCcw, Package, QrCode, CreditCard, Wallet, ArrowRight, Clock, Code2 } from 'lucide-vue-next';
import ConquistasWidget from '@/components/layout/ConquistasWidget.vue';
import DashboardPeriodToolbar from '@/components/dashboard/DashboardPeriodToolbar.vue';
import DashboardCustomPeriodForm from '@/components/dashboard/DashboardCustomPeriodForm.vue';
import PnPanel from '@/components/paynext/PnPanel.vue';
import PnKpiCard from '@/components/paynext/PnKpiCard.vue';
import PnMeter from '@/components/paynext/PnMeter.vue';
import PnBarChart from '@/components/paynext/PnBarChart.vue';
import PnDonutChart from '@/components/paynext/PnDonutChart.vue';
import PnAnimatedNumber from '@/components/paynext/PnAnimatedNumber.vue';

/** Dashboard do vendedor no layout "Gestão de caixa" do Paynext DS. */
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
    vendas_api: { type: Number, default: 0 },
    quantidade_vendas_api: { type: Number, default: 0 },
    vendas_pendentes: { type: Number, default: 0 },
    quantidade_vendas: { type: Number, default: 0 },
    ticket_medio: { type: Number, default: 0 },
    formas_pagamento: { type: Array, default: () => [] },
    taxa_conversao: { type: Number, default: 0 },
    abandono_carrinho: { type: Number, default: 0 },
    taxa_abandono_compras: { type: Number, default: 0 },
    compras_periodo: { type: Number, default: 0 },
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

const CHART_COLORS = ['var(--chart-1)', 'var(--chart-2)', 'var(--chart-3)', 'var(--chart-4)', 'var(--chart-5)'];

function methodIcon(metodo) {
    const m = String(metodo || '').toLowerCase();
    if (m.includes('pix')) return QrCode;
    if (m.includes('card') || m.includes('cart') || m.includes('credit')) return CreditCard;
    if (m.includes('boleto') || m.includes('bank_slip')) return Receipt;
    return Wallet;
}

/* Barras do painel principal: últimos 12 pontos do gráfico de vendas */
const recentPoints = computed(() => props.grafico_vendas.slice(-12));
const barData = computed(() => recentPoints.value.map((p) => Number(p.total) || 0));
const barLabels = computed(() =>
    recentPoints.value.map((p) => {
        const raw = String(p.data ?? '');
        const parts = raw.split('-');
        if (parts.length === 3) return `${parts[2]}/${parts[1]}`;
        if (parts.length === 2) return `${parts[1]}/${parts[0].slice(2)}`;
        return /^\d+$/.test(raw) ? `${Number(raw)}h` : raw;
    }),
);
const kpiBars = computed(() => (barData.value.length >= 3 ? barData.value.slice(-10).map((v) => v + 1) : null));

const methodsTotal = computed(() => props.formas_pagamento.reduce((a, fp) => a + (Number(fp.total) || 0), 0));
const methods = computed(() =>
    [...props.formas_pagamento]
        .sort((a, b) => (Number(b.total) || 0) - (Number(a.total) || 0))
        .map((fp, i) => ({
            ...fp,
            share: methodsTotal.value ? ((Number(fp.total) || 0) / methodsTotal.value) * 100 : 0,
            color: CHART_COLORS[i % CHART_COLORS.length],
            icon: methodIcon(fp.metodo),
        })),
);
const methodTiles = computed(() => methods.value.slice(0, 3));
const donutParts = computed(() => methods.value.map((m) => ({ value: m.share || 0.0001, color: m.color })));

function percent(v) {
    return `${new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 1 }).format(Number(v) || 0)}%`;
}
function compactBRL(v) {
    if (!props.valuesVisible) return '••••';
    const n = Number(v) || 0;
    if (n >= 1_000_000) return `R$ ${new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 1 }).format(n / 1_000_000)} mi`;
    if (n >= 1_000) return `R$ ${new Intl.NumberFormat('pt-BR', { maximumFractionDigits: 1 }).format(n / 1_000)} mil`;
    return props.displayCurrency(n);
}

/* Gráfico de área no padrão do DS: linha lime, gradiente suave, sem rótulos em cada ponto */
const areaOptions = computed(() => ({
    ...props.chartOptions,
    colors: ['#E1FB4D'],
    dataLabels: { enabled: false },
    stroke: { curve: 'smooth', width: 3 },
    markers: { size: 0, strokeWidth: 0, hover: { size: 6 } },
    fill: { type: 'gradient', gradient: { shadeIntensity: 0, opacityFrom: 0.35, opacityTo: 0.02, stops: [0, 95] } },
    grid: { ...props.chartOptions.grid, strokeDashArray: 0, borderColor: 'rgba(138,146,150,.16)' },
    chart: { ...props.chartOptions.chart, animations: { enabled: true, speed: 1400 } },
}));
</script>

<template>
    <div class="space-y-5">
        <div v-if="hasAchievementsProgress" class="lg:hidden">
            <ConquistasWidget variant="dashboard" />
        </div>

        <DashboardPeriodToolbar
            :period="period"
            :period-options="periodOptions"
            :values-visible="valuesVisible"
            :period-label="periodLabel"
            :hide-values-label="hideValuesLabel"
            :show-values-label="showValuesLabel"
            @update:period="emit('update:period', $event)"
            @toggle-values="emit('toggle-values')"
        />

        <DashboardCustomPeriodForm />

        <!-- Saldo do período + painel de contraste -->
        <div class="grid gap-5 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <PnPanel :index="0" :pad="28">
                <div class="grid items-end gap-8 md:grid-cols-[1.3fr_1fr_1fr]">
                    <div class="min-w-0">
                        <div class="text-[13px] font-light" style="color: var(--text-secondary)">{{ labels.totalSales }}</div>
                        <div class="pn-hero-number">
                            <template v-if="valuesVisible">
                                <sup>R$</sup><PnAnimatedNumber :value="vendas_totais" :decimals="2" />
                            </template>
                            <template v-else>••••••</template>
                        </div>
                    </div>
                    <div>
                        <div class="mb-4 flex items-baseline justify-between text-[13px] font-light" style="color: var(--text-secondary)">
                            <span>{{ labels.conversionRate }}</span>
                            <b class="font-medium" style="color: var(--text-primary)">{{ valuesVisible ? percent(taxa_conversao) : '—' }}</b>
                        </div>
                        <PnMeter :value="valuesVisible ? taxa_conversao : 0" />
                    </div>
                    <div>
                        <div class="mb-4 flex items-baseline justify-between text-[13px] font-light" style="color: var(--text-secondary)">
                            <span>{{ labels.cartAbandonment }}</span>
                            <b class="font-medium" style="color: var(--text-primary)">{{ valuesVisible ? percent(taxa_abandono_compras) : '—' }}</b>
                        </div>
                        <PnMeter :value="valuesVisible ? taxa_abandono_compras : 0" />
                    </div>
                </div>
                <div class="mt-7">
                    <PnBarChart
                        v-if="barData.length"
                        :key="period"
                        :data="valuesVisible ? barData : barData.map(() => 1)"
                        :labels="barLabels"
                        :height="150"
                        :format="(v) => compactBRL(v)"
                    />
                    <p v-else class="flex h-[150px] items-center justify-center text-sm font-light" style="color: var(--text-tertiary)">
                        {{ labels.noSalesData }}
                    </p>
                </div>
            </PnPanel>

            <PnPanel :index="1" variant="contrast" :pad="28" class="flex flex-col">
                <div class="flex items-start justify-between gap-3">
                    <div class="text-[13px] font-light" style="color: var(--text-secondary)">{{ labels.pendingSales }}</div>
                    <span class="pn-kpi__icon" style="width: 36px; height: 36px"><Clock class="h-[18px] w-[18px]" /></span>
                </div>
                <div class="pn-hero-number pn-hero-number--md">
                    <template v-if="valuesVisible"><sup>R$</sup><PnAnimatedNumber :value="vendas_pendentes" :decimals="2" /></template>
                    <template v-else>••••••</template>
                </div>
                <span class="pn-badge pn-badge--neutral self-start">{{ displayNumber(compras_periodo) }} {{ labels.purchasesCount }}</span>

                <div v-if="methodTiles.length" class="mt-6 grid items-end gap-2.5" :style="{ gridTemplateColumns: `repeat(${methodTiles.length}, minmax(0,1fr))` }">
                    <div
                        v-for="(m, i) in methodTiles"
                        :key="m.metodo"
                        class="pn-method-tile"
                        :class="i === 0 ? 'pn-method-tile--on' : ''"
                        :style="{ height: `${92 + Math.round((m.share / 100) * 40)}px` }"
                    >
                        <i v-if="i === 0" class="pn-method-tile__hatch" aria-hidden="true" />
                        <component :is="m.icon" class="relative h-5 w-5" :weight="i === 0 ? 'fill' : 'duotone'" />
                        <span class="relative text-[11px] font-light leading-[14px]">
                            <b class="block text-xs font-medium">{{ m.label }}</b>
                            {{ valuesVisible ? percent(m.share) : '—' }}
                        </span>
                    </div>
                </div>
                <Link href="/vendas" class="pn-btn pn-btn--accent pn-btn--block pn-keep-shape mt-5 no-underline hover:no-underline" style="background: #fff; color: #0c0e0f">
                    Ver vendas <ArrowRight class="h-4 w-4" />
                </Link>
            </PnPanel>
        </div>

        <!-- KPIs -->
        <div class="grid gap-5 sm:grid-cols-2" :class="vendas_api > 0 || quantidade_vendas_api > 0 ? 'xl:grid-cols-5' : 'xl:grid-cols-4'">
            <PnKpiCard :index="2" :label="labels.salesCount" :icon="ShoppingCart" :value="quantidade_vendas" :bars="kpiBars" :masked="!valuesVisible" />
            <PnKpiCard :index="3" :label="labels.avgTicket" :icon="CircleDollarSign" :value="ticket_medio" prefix="R$ " :decimals="2" :bars="kpiBars ? [...kpiBars].reverse() : null" :masked="!valuesVisible" />
            <PnKpiCard :index="4" :label="labels.refunds" :icon="RotateCcw" :value="reembolsos_total" prefix="R$ " :decimals="2" :caption="`${displayNumber(reembolsos_count)} ${labels.ordersCount}`" :masked="!valuesVisible" />
            <PnKpiCard v-if="vendas_api > 0 || quantidade_vendas_api > 0" :index="5" :label="labels.apiSales" :icon="Code2" :value="vendas_api" prefix="R$ " :decimals="2" :caption="`${displayNumber(quantidade_vendas_api)} ${labels.ordersCount}`" :masked="!valuesVisible" />
            <PnKpiCard :index="6" :label="labels.products" :icon="Package" :value="quantidade_produtos" :caption="`${displayNumber(abandono_carrinho)} ${labels.abandonedCount}`" :masked="!valuesVisible" />
        </div>

        <!-- Desempenho + meios de pagamento -->
        <div class="grid gap-5 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <PnPanel :index="6" :title="labels.salesPerformance" :sub="periodOptions.find((o) => o.value === period)?.label || ''">
                <div class="min-h-[280px]">
                    <VueApexCharts v-if="grafico_vendas.length" :key="`${period}-${valuesVisible}`" type="area" height="280" :options="areaOptions" :series="chartSeries" />
                    <p v-else class="flex h-[280px] items-center justify-center text-sm font-light" style="color: var(--text-tertiary)">
                        {{ labels.noSalesData }}
                    </p>
                </div>
            </PnPanel>

            <PnPanel :index="7" :title="labels.paymentMethods" sub="Participação no volume">
                <template v-if="methods.length">
                    <PnDonutChart :size="190" :parts="donutParts" :label="compactBRL(methodsTotal)" sub="Volume no período" />
                    <div class="mt-5 flex flex-col gap-3">
                        <div v-for="m in methods" :key="m.metodo" class="flex items-center justify-between gap-3 text-[13px] font-light">
                            <span class="flex min-w-0 items-center gap-2.5">
                                <i class="inline-block h-2 w-2 shrink-0 rounded-full" :style="{ background: m.color, boxShadow: `0 0 8px ${m.color}` }" />
                                <span class="truncate">{{ m.label }}</span>
                            </span>
                            <span class="flex shrink-0 items-baseline gap-2">
                                <span style="color: var(--text-tertiary)">{{ displayCurrency(m.total) }}</span>
                                <b class="font-medium">{{ valuesVisible ? percent(m.share) : '—' }}</b>
                            </span>
                        </div>
                    </div>
                </template>
                <p v-else class="flex h-[260px] items-center justify-center text-center text-sm font-light" style="color: var(--text-tertiary)">
                    {{ labels.noPayments }}
                </p>
            </PnPanel>
        </div>
    </div>
</template>

<style scoped>
.pn-hero-number {
    margin-top: 6px;
    font: 400 44px/52px var(--font-display);
    letter-spacing: -0.03em;
    color: var(--text-primary);
    font-variant-numeric: tabular-nums;
    white-space: nowrap;
}
@media (min-width: 1280px) {
    .pn-hero-number {
        font-size: 52px;
        line-height: 60px;
    }
}
.pn-hero-number--md {
    margin: 12px 0 6px;
    font-size: 36px !important;
    line-height: 44px !important;
}
.pn-hero-number sup {
    font-size: 0.42em;
    vertical-align: 0.55em;
    margin-right: 6px;
    color: var(--text-secondary);
}
.pn-method-tile {
    position: relative;
    overflow: hidden;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    padding: 12px;
    border-radius: 18px;
    background: rgba(255, 255, 255, 0.08);
    color: #f4f6f7;
    transition: all 0.4s var(--ease-out);
}
.pn-method-tile--on {
    background: var(--lime-300);
    color: var(--ink-900);
    box-shadow: 0 0 32px rgba(225, 251, 77, 0.35);
}
.pn-method-tile__hatch {
    position: absolute;
    inset: 0;
    background: var(--hatch-lime);
    opacity: 0.5;
}
</style>
