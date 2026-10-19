<script setup>
defineProps({
    id: { type: String, default: '' },
    title: { type: String, default: '' },
});
</script>

<template>
    <section v-if="id || $slots.default" :id="id" class="doc-section scroll-mt-28">
        <h2 v-if="title" class="doc-section-title">{{ title }}</h2>
        <slot />
    </section>
</template>

<style scoped>
.doc-section-title {
    margin-top: 4rem;
    margin-bottom: 1.5rem;
    border-top: 1px solid var(--border-default);
    padding-top: 2rem;
    font-size: 1.5rem;
    font-weight: 500;
    letter-spacing: -0.025em;
    color: var(--text-primary);
}
.doc-section-title:first-child {
    margin-top: 2rem;
    border-top: 0;
    padding-top: 0;
}
</style>
