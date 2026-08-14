<script setup>
import { Eye, EyeOff } from 'lucide-vue-next';

defineProps({
    period: { type: String, required: true },
    periodOptions: { type: Array, required: true },
    valuesVisible: { type: Boolean, required: true },
    periodLabel: { type: String, default: 'Período' },
    hideValuesLabel: { type: String, default: 'Ocultar valores' },
    showValuesLabel: { type: String, default: 'Mostrar valores' },
});

const emit = defineEmits(['update:period', 'toggle-values']);
</script>

<template>
    <div class="flex w-full items-center justify-end gap-2">
        <button
            type="button"
            :aria-label="valuesVisible ? hideValuesLabel : showValuesLabel"
            class="prime-icon-btn flex h-9 w-9 shrink-0 items-center justify-center rounded-lg transition-colors"
            @click="emit('toggle-values')"
        >
            <Eye v-if="valuesVisible" class="h-4 w-4" aria-hidden="true" />
            <EyeOff v-else class="h-4 w-4" aria-hidden="true" />
        </button>

        <nav
            class="prime-segmented max-w-full overflow-x-auto no-scrollbar"
            :aria-label="periodLabel"
        >
            <button
                v-for="opt in periodOptions"
                :key="opt.value"
                type="button"
                :aria-current="period === opt.value ? 'true' : undefined"
                class="prime-segmented-item"
                :class="period === opt.value ? 'prime-segmented-item-active' : ''"
                @click="emit('update:period', opt.value)"
            >
                {{ opt.label }}
            </button>
        </nav>
    </div>
</template>
