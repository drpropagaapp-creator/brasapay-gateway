<script setup>
import { computed } from 'vue';

/** Barras arredondadas que sobem da base; a barra de destaque é lime hachurada. */
const props = defineProps({
    data: { type: Array, default: () => [] },
    labels: { type: Array, default: null },
    highlight: { type: Number, default: null },
    height: { type: Number, default: 160 },
    format: { type: Function, default: (v) => String(v) },
});

const max = computed(() => Math.max(1, ...props.data.map((v) => Number(v) || 0)));
const hot = computed(() => (props.highlight == null ? props.data.length - 1 : props.highlight));
</script>

<template>
    <div>
        <div class="flex items-end gap-2" :style="{ height: `${height}px` }">
            <div
                v-for="(v, k) in data"
                :key="k"
                :title="format(v)"
                class="pn-bar"
                :class="k === hot ? 'pn-bar--hot' : ''"
                :style="{ '--k': k, height: `${Math.max(8, ((Number(v) || 0) / max) * 100)}%` }"
            />
        </div>
        <div v-if="labels" class="mt-2 flex gap-2">
            <!-- Celular: com muitas barras, um rótulo sim e um não (o destaque sempre aparece), sem cortar o texto. -->
            <span
                v-for="(l, k) in labels"
                :key="k"
                class="min-w-0 flex-1 whitespace-nowrap text-center text-[10.5px] font-light sm:truncate"
                :class="labels.length > 7 && (hot - k) % 2 !== 0 ? 'max-sm:invisible' : ''"
                :style="{ color: k === hot ? 'var(--text-primary)' : 'var(--text-tertiary)' }"
            >{{ l }}</span>
        </div>
    </div>
</template>
