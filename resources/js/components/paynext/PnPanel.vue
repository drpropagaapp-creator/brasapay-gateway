<script setup>
import PnGlassCard from '@/components/paynext/PnGlassCard.vue';

/** Painel do UI kit Paynext: GlassCard com título, subtítulo e ações à direita. */
defineProps({
    title: { type: String, default: '' },
    sub: { type: String, default: '' },
    variant: { type: String, default: 'default' },
    index: { type: Number, default: 0 },
    pad: { type: [Number, String], default: 24 },
});
</script>

<template>
    <PnGlassCard animate :index="index" :variant="variant" :style="{ padding: `${pad}px` }">
        <div v-if="title || $slots.right" class="relative mb-[18px] flex items-start justify-between gap-3">
            <div class="min-w-0">
                <h3 class="pn-panel__title">{{ title }}</h3>
                <div v-if="sub" class="pn-panel__sub">{{ sub }}</div>
            </div>
            <slot name="right" />
        </div>
        <div class="relative">
            <slot />
        </div>
    </PnGlassCard>
</template>

<style scoped>
.pn-panel__title {
    font: 500 16px/22px var(--font-display);
    letter-spacing: var(--tracking-heading);
}
.pn-panel__sub {
    margin-top: 3px;
    font: 300 12px/16px var(--font-body);
    color: var(--text-tertiary);
}
</style>
