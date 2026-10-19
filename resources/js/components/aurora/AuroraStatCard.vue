<script setup>
import { computed } from 'vue';
import PnGlassCard from '@/components/paynext/PnGlassCard.vue';

/** Cartão de métrica no padrão KpiCard do Paynext DS (valor já formatado pela página). */
const props = defineProps({
    icon: { type: Object, required: true },
    label: { type: String, required: true },
    value: { type: String, required: true },
});

/* "R$ 1.234,56" → R$ em sobrescrito pequeno, como os números do DS */
const parts = computed(() => {
    const raw = String(props.value ?? '');
    const match = raw.match(/^\s*(R\$)\s*(.+)$/);
    return match ? { currency: match[1], amount: match[2] } : { currency: '', amount: raw };
});
</script>

<template>
    <PnGlassCard lift class="pn-kpi pn-stat-card">
        <div class="pn-kpi__top">
            <span class="min-w-0 truncate">{{ label }}</span>
            <span class="pn-kpi__icon shrink-0"><component :is="icon" class="h-5 w-5" aria-hidden="true" /></span>
        </div>
        <div class="pn-kpi__value truncate">
            <sup v-if="parts.currency">{{ parts.currency }}</sup>{{ parts.amount }}
        </div>
    </PnGlassCard>
</template>

<style scoped>
.pn-stat-card {
    padding: 20px 22px;
    gap: 12px;
}
.pn-stat-card .pn-kpi__value {
    font: 400 28px/34px var(--font-display);
    letter-spacing: var(--tracking-heading);
}
</style>
