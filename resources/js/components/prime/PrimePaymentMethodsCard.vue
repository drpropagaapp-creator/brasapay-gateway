<script setup>
import { computed } from 'vue';
import { CreditCard } from 'lucide-vue-next';

const props = defineProps({
    formas_pagamento: { type: Array, default: () => [] },
    paymentMethodsLabel: { type: String, required: true },
    noPaymentsLabel: { type: String, required: true },
    displayCurrency: { type: Function, required: true },
    displayNumber: { type: Function, required: true },
});

const totalPeriodo = computed(() =>
    props.formas_pagamento.reduce((sum, fp) => sum + (Number(fp.total) || 0), 0)
);

function percentOfTotal(fp) {
    if (!totalPeriodo.value) return 0;
    return Math.round((Number(fp.total) / totalPeriodo.value) * 100);
}
</script>

<template>
    <div class="prime-card flex flex-col p-5">
        <h2 class="prime-section-title mb-4 flex items-center gap-2">
            <CreditCard class="h-4 w-4 text-[var(--color-primary)]" aria-hidden="true" />
            {{ paymentMethodsLabel }}
        </h2>

        <ul class="space-y-4">
            <li
                v-for="fp in formas_pagamento"
                :key="fp.metodo"
            >
                <div class="mb-1.5 flex items-baseline justify-between gap-2">
                    <span class="prime-fg text-sm font-medium">{{ fp.label }}</span>
                    <span class="prime-card-value prime-num text-sm font-semibold">
                        {{ displayCurrency(fp.total) }}
                        <span class="prime-fg-subtle ml-1 text-xs font-normal">{{ percentOfTotal(fp) }}%</span>
                    </span>
                </div>
                <div class="prime-progress-track">
                    <div
                        class="prime-progress-fill transition-all duration-500"
                        :style="{ width: `${percentOfTotal(fp)}%` }"
                    />
                </div>
                <p class="prime-fg-subtle mt-1 text-[11px]">
                    {{ displayNumber(fp.quantidade) }} transação(ões)
                </p>
            </li>
            <li v-if="!formas_pagamento.length" class="prime-fg-muted py-4 text-center text-sm">
                {{ noPaymentsLabel }}
            </li>
        </ul>

        <div v-if="formas_pagamento.length" class="prime-divider mt-auto flex items-baseline justify-between border-t pt-4">
            <span class="prime-kpi-label">Total no período</span>
            <span class="prime-card-value prime-num text-base font-bold">
                {{ displayCurrency(totalPeriodo) }}
            </span>
        </div>
    </div>
</template>
