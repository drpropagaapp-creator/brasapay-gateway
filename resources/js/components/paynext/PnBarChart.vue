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
            <span
                v-for="(l, k) in labels"
                :key="k"
                class="flex-1 truncate text-center text-[10.5px] font-light"
                :style="{ color: k === hot ? 'var(--text-primary)' : 'var(--text-tertiary)' }"
            >{{ l }}</span>
        </div>
    </div>
</template>
