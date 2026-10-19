<script setup>
import { useAuthTheme } from '@/composables/useAuthTheme';
import AuthPageShellPaynext from '@/components/auth/AuthPageShellPaynext.vue';

defineProps({
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    variant: {
        type: String,
        default: 'seller',
        validator: (v) => ['seller', 'platform', 'neutral'].includes(v),
    },
    wide: { type: Boolean, default: false },
});

// Sincroniza tema global (panel_color_scheme) nas telas de auth — ignora localStorage do painel
useAuthTheme();
</script>

<template>
    <AuthPageShellPaynext :title="title" :subtitle="subtitle" :variant="variant" :wide="wide">
        <slot />
        <template v-if="$slots.footer" #footer>
            <slot name="footer" />
        </template>
    </AuthPageShellPaynext>
</template>
