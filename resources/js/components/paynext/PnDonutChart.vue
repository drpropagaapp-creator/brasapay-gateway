<script setup>
import { computed } from 'vue';

/** Rosca com segmentos que varrem em sequência (DS DonutChart). */
const props = defineProps({
    parts: { type: Array, default: () => [] }, // [{ value, color }]
    size: { type: Number, default: 180 },
    label: { type: String, default: '' },
    sub: { type: String, default: '' },
});

const R = 66;
const C = 2 * Math.PI * R;

const segments = computed(() => {
    const total = props.parts.reduce((a, p) => a + (Number(p.value) || 0), 0);
    if (!total) return [];
    let off = 0;
    return props.parts.map((p, i) => {
        const share = (Number(p.value) || 0) / total;
        const len = Math.max(0, share * C - 5);
        const seg = { color: p.color, dash: `${len} ${C - len}`, offset: -off - 2, delay: `${i * 0.18}s`, glow: i === 0 };
        off += share * C;
        return seg;
    });
});
</script>

<template>
    <svg viewBox="0 0 180 180" :width="size" :height="size" class="mx-auto block overflow-visible">
        <circle cx="90" cy="90" :r="R" fill="none" stroke="var(--bg-muted)" stroke-width="16" />
        <g transform="rotate(-90 90 90)">
            <circle
                v-for="(s, i) in segments"
                :key="i"
                class="pn-seg-arc"
                cx="90"
                cy="90"
                :r="R"
                fill="none"
                :stroke="s.color"
                stroke-width="16"
                stroke-linecap="round"
                :stroke-dasharray="s.dash"
                :stroke-dashoffset="s.offset"
                :style="{ animationDelay: s.delay, filter: s.glow ? `drop-shadow(0 0 8px ${s.color})` : undefined }"
            />
        </g>
        <text v-if="label" x="90" y="92" text-anchor="middle" font-size="20" fill="var(--text-primary)" font-family="var(--font-display)">{{ label }}</text>
        <text v-if="sub" x="90" y="110" text-anchor="middle" font-size="10" fill="var(--text-tertiary)">{{ sub }}</text>
    </svg>
</template>
