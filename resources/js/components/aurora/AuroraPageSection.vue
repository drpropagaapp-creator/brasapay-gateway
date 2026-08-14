<script setup>
import { useSellerDashboardTemplate } from '@/composables/useSellerDashboardTemplate';

defineProps({
    flush: { type: Boolean, default: false },
});

const { isAurora, isKawaii, isPrime, isStudio, isThemedShell } = useSellerDashboardTemplate();
</script>

<template>
    <section
        v-if="isAurora"
        class="aurora-page-section"
        :class="flush ? 'aurora-page-section-flush' : ''"
    >
        <slot />
    </section>
    <section
        v-else-if="isPrime"
        class="prime-page-section"
        :class="flush ? 'prime-page-section-flush' : ''"
    >
        <slot />
    </section>
    <section
        v-else-if="isStudio"
        class="studio-page-section"
        :class="flush ? 'studio-page-section-flush' : ''"
    >
        <slot />
    </section>
    <section
        v-else-if="isKawaii"
        class="kawaii-page-section"
    >
        <slot />
    </section>
    <div v-else>
        <slot />
    </div>
</template>
