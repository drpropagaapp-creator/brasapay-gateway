<script setup>
import { computed, onBeforeUnmount, ref, watch } from 'vue';

/** Número com contagem 0 → valor (ease-out quart, 1400ms), formatado em pt-BR. */
const props = defineProps({
    value: { type: Number, default: 0 },
    prefix: { type: String, default: '' },
    suffix: { type: String, default: '' },
    decimals: { type: Number, default: 0 },
    duration: { type: Number, default: 1400 },
    locale: { type: String, default: 'pt-BR' },
});

const current = ref(0);
let raf = null;

function run(target) {
    if (raf) cancelAnimationFrame(raf);
    const reduce = typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    if (reduce || typeof requestAnimationFrame === 'undefined') {
        current.value = target;
        return;
    }
    const from = current.value;
    let t0 = null;
    const tick = (t) => {
        if (t0 === null) t0 = t;
        const p = Math.min(1, (t - t0) / props.duration);
        const eased = 1 - Math.pow(1 - p, 4);
        current.value = from + (target - from) * eased;
        if (p < 1) raf = requestAnimationFrame(tick);
    };
    raf = requestAnimationFrame(tick);
}

watch(() => props.value, (v) => run(Number(v) || 0), { immediate: true });
onBeforeUnmount(() => raf && cancelAnimationFrame(raf));

const text = computed(() =>
    new Intl.NumberFormat(props.locale, {
        minimumFractionDigits: props.decimals,
        maximumFractionDigits: props.decimals,
    }).format(current.value),
);
</script>

<template>
    <span style="font-variant-numeric: tabular-nums">{{ prefix }}{{ text }}{{ suffix }}</span>
</template>
