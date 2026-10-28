<script setup>
import { ChevronDown } from 'lucide-vue-next';

/** Título de grupo da sidebar que abre e fecha a seção. Fechado e com a página atual dentro, ganha um ponto. */
defineProps({
    label: { type: String, required: true },
    open: { type: Boolean, default: false },
    count: { type: Number, default: 0 },
    hasActive: { type: Boolean, default: false },
    /** Pendências somadas dos itens (admin): aparecem no título com o grupo fechado. */
    pending: { type: Number, default: 0 },
    controls: { type: String, required: true },
});
defineEmits(['toggle']);
</script>

<template>
    <button
        type="button"
        class="pn-sidebar__sec group flex w-full items-center justify-between rounded-full text-left transition-colors hover:text-[var(--text-secondary)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--focus-ring)]"
        :aria-expanded="open"
        :aria-controls="controls"
        @click="$emit('toggle')"
    >
        <span class="flex items-center gap-1.5">
            {{ label }}
            <span v-if="!open && hasActive" class="h-1.5 w-1.5 rounded-full bg-[var(--lime-400)]" aria-hidden="true" />
        </span>
        <span class="flex items-center gap-1.5">
            <span v-if="!open && pending > 0" class="pn-nav__count" :aria-label="`${pending} pendente(s)`">{{ pending > 99 ? '99+' : pending }}</span>
            <span v-else-if="!open" class="text-[10px] font-normal tabular-nums normal-case tracking-normal opacity-70">{{ count }}</span>
            <ChevronDown class="h-3.5 w-3.5 transition-transform duration-200 motion-reduce:transition-none" :class="open ? 'rotate-180' : ''" aria-hidden="true" />
        </span>
    </button>
</template>
