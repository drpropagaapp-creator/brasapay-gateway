<script setup>
import { computed } from 'vue';
import {
    SelectContent,
    SelectItem,
    SelectItemIndicator,
    SelectItemText,
    SelectPortal,
    SelectRoot,
    SelectTrigger,
    SelectViewport,
} from 'radix-vue';
import { Eye, EyeOff, ChevronDown, Check, Calendar } from 'lucide-vue-next';

/** Período do dashboard: barra preta em pílula com o ativo em lime (DS Tabs) + olho para ocultar valores. */
const props = defineProps({
    period: { type: String, required: true },
    periodOptions: { type: Array, required: true },
    valuesVisible: { type: Boolean, required: true },
    periodLabel: { type: String, default: 'Período' },
    hideValuesLabel: { type: String, default: 'Ocultar valores' },
    showValuesLabel: { type: String, default: 'Mostrar valores' },
    auroraStyle: { type: Boolean, default: false },
});

const emit = defineEmits(['update:period', 'toggle-values']);

function formatLongDate(date) {
    return date.toLocaleDateString('pt-BR', { day: 'numeric', month: 'long' });
}

const periodDisplayLabel = computed(() => {
    const now = new Date();
    if (props.period === 'hoje') {
        return `Hoje, ${formatLongDate(now)}`;
    }
    if (props.period === 'ontem') {
        const yesterday = new Date(now);
        yesterday.setDate(yesterday.getDate() - 1);
        return `Ontem, ${formatLongDate(yesterday)}`;
    }
    const selected = props.periodOptions.find((opt) => opt.value === props.period);
    return selected?.label ?? props.periodLabel;
});
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="hidden items-center gap-3 lg:flex">
            <span class="pn-eyebrow">{{ periodLabel }}</span>
            <div class="pn-tabs" role="tablist" :aria-label="periodLabel">
                <button
                    v-for="opt in periodOptions"
                    :key="opt.value"
                    type="button"
                    role="tab"
                    class="pn-tab pn-keep-shape"
                    :class="opt.value === period ? 'pn-tab--active' : ''"
                    :aria-selected="opt.value === period"
                    @click="emit('update:period', opt.value)"
                >
                    {{ opt.label }}
                </button>
            </div>
        </div>

        <SelectRoot :model-value="period" @update:model-value="emit('update:period', $event)">
            <SelectTrigger type="button" :aria-label="periodLabel" class="pn-chip pn-keep-shape min-w-0 flex-1 justify-between lg:hidden">
                <span class="flex min-w-0 items-center gap-2">
                    <Calendar class="h-4 w-4 shrink-0" />
                    <span class="truncate">{{ periodDisplayLabel }}</span>
                </span>
                <ChevronDown class="h-4 w-4 shrink-0 opacity-60" />
            </SelectTrigger>
            <SelectPortal to="body">
                <SelectContent class="pn-popover z-[100000] min-w-[var(--radix-select-trigger-width)] p-1.5" :side-offset="6" position="popper" :avoid-collisions="true">
                    <SelectViewport>
                        <SelectItem
                            v-for="opt in periodOptions"
                            :key="opt.value"
                            :value="opt.value"
                            class="pn-popover__item relative cursor-pointer select-none outline-none data-[highlighted]:bg-[var(--bg-hover)] data-[state=checked]:bg-[var(--bg-selected)] data-[state=checked]:text-[var(--text-primary)]"
                        >
                            <SelectItemText>{{ opt.label }}</SelectItemText>
                            <SelectItemIndicator><Check class="h-4 w-4" weight="fill" /></SelectItemIndicator>
                        </SelectItem>
                    </SelectViewport>
                </SelectContent>
            </SelectPortal>
        </SelectRoot>

        <div class="flex items-center gap-3">
            <span class="hidden text-[13px] font-light lg:inline" style="color: var(--text-secondary)">{{ periodDisplayLabel }}</span>
            <button
                type="button"
                class="pn-iconbtn pn-keep-shape"
                :aria-label="valuesVisible ? hideValuesLabel : showValuesLabel"
                :title="valuesVisible ? hideValuesLabel : showValuesLabel"
                @click="emit('toggle-values')"
            >
                <Eye v-if="valuesVisible" class="h-5 w-5" />
                <EyeOff v-else class="h-5 w-5" />
            </button>
        </div>
    </div>
</template>
