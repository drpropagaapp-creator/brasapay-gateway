<script setup>
import { computed } from 'vue';

/** GlassCard do Paynext DS: vidro com spotlight seguindo o cursor. */
const props = defineProps({
    variant: { type: String, default: 'default' }, // default | strong | contrast | lime
    spotlight: { type: Boolean, default: true },
    lift: { type: Boolean, default: false },
    animate: { type: Boolean, default: false },
    index: { type: Number, default: 0 },
    tag: { type: String, default: 'div' },
});

const classes = computed(() => [
    'pn-glass',
    props.variant === 'strong' ? 'pn-glass--strong' : '',
    props.variant === 'contrast' ? 'pn-glass--contrast' : '',
    props.variant === 'lime' ? 'pn-glass--lime' : '',
    props.spotlight ? 'pn-glass--spot' : '',
    props.lift ? 'pn-glass--lift' : '',
    props.animate ? 'pn-anim' : '',
]);

function onMove(e) {
    if (!props.spotlight) return;
    const r = e.currentTarget.getBoundingClientRect();
    e.currentTarget.style.setProperty('--mx', `${e.clientX - r.left}px`);
    e.currentTarget.style.setProperty('--my', `${e.clientY - r.top}px`);
}
</script>

<template>
    <component
        :is="tag"
        :class="classes"
        :data-surface="variant === 'contrast' || variant === 'lime' ? variant : undefined"
        :style="{ '--i': index }"
        @mousemove="onMove"
    >
        <slot />
    </component>
</template>
