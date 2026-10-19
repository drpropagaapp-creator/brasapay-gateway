<script setup>
import { computed } from 'vue';
import { cn } from '@/lib/utils';

/**
 * Botão do Paynext DS (pílula). primary = lime com brilho; default = acento (ink/branco);
 * secondary/outline = vidro; destructive = perigo; ghost/link = discretos.
 */
const props = defineProps({
    variant: {
        type: String,
        default: 'default',
        validator: (v) => ['default', 'primary', 'destructive', 'outline', 'secondary', 'ghost', 'link'].includes(v),
    },
    size: {
        type: String,
        default: 'default',
        validator: (v) => ['default', 'sm', 'lg', 'icon'].includes(v),
    },
    as: { type: String, default: 'button' },
    class: { type: [String, Object, Array], default: '' },
});

const VARIANTS = {
    default: 'pn-btn--accent',
    primary: 'pn-btn--primary',
    destructive: 'pn-btn--danger',
    outline: 'pn-btn--secondary',
    secondary: 'pn-btn--secondary',
    ghost: 'pn-btn--ghost',
    link: 'pn-btn--link',
};
const SIZES = { default: '', sm: 'pn-btn--sm', lg: 'pn-btn--lg', icon: 'pn-btn--icon' };

const classes = computed(() => cn('pn-btn pn-keep-shape', VARIANTS[props.variant], SIZES[props.size], props.class));
</script>

<template>
    <component :is="as" :class="classes">
        <slot />
    </component>
</template>
