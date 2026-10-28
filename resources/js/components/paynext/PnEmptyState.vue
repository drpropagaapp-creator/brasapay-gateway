<script setup>
/** Estado vazio: diz o que falta e o próximo passo (ação no slot). */
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: '' },
    icon: { type: [Object, Function], default: null },
});
</script>

<template>
    <div class="pn-glass flex flex-col items-center gap-2 px-6 py-10 text-center">
        <span v-if="icon" class="mb-1 flex h-12 w-12 items-center justify-center rounded-full border border-[var(--border-strong)] text-[var(--text-tertiary)]">
            <component :is="icon" class="h-6 w-6" aria-hidden="true" />
        </span>
        <p class="text-sm font-medium text-[var(--text-primary)]">{{ title }}</p>
        <p v-if="description" class="max-w-md text-xs text-[var(--text-tertiary)]">{{ description }}</p>
        <div v-if="$slots.default" class="mt-2 flex flex-wrap justify-center gap-2"><slot /></div>
    </div>
</template>
