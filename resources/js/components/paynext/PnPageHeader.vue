<script setup>
/** Cabeçalho padrão das páginas do painel: ícone, título, descrição curta e ações à direita. */
defineProps({
    title: { type: String, required: true },
    description: { type: String, default: '' },
    icon: { type: [Object, Function], default: null },
    eyebrow: { type: String, default: '' },
});
</script>

<template>
    <header class="flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <p v-if="eyebrow" class="mb-1 text-[11px] font-medium uppercase tracking-[0.08em] text-[var(--text-tertiary)]">{{ eyebrow }}</p>
            <h1 class="flex items-center gap-2.5 text-2xl font-semibold tracking-tight text-[var(--text-primary)]">
                <span v-if="icon" class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[var(--lime-300)] text-[var(--ink-900)]">
                    <component :is="icon" class="h-5 w-5" aria-hidden="true" />
                </span>
                {{ title }}
            </h1>
            <p v-if="description" class="mt-1.5 max-w-2xl text-sm text-[var(--text-secondary)]">{{ description }}</p>
        </div>
        <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2">
            <slot name="actions" />
        </div>
    </header>
</template>
