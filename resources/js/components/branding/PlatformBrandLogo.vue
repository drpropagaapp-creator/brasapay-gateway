<script setup>
import { computed } from 'vue';
import { usePlatformBranding } from '@/composables/usePlatformBranding';
import PnLogoImage from '@/components/paynext/PnLogoImage.vue';

/** Logo da plataforma (Branding do admin), recortado da margem transparente; sem logo, o nome no wordmark do DS. */
const props = defineProps({
    imgClass: { type: String, default: '' },
    iconClass: { type: String, default: '' },
    /** auto = claro/escuro pelo tema; dark = fundo escuro fixo (docs) */
    theme: { type: String, default: 'auto' },
    height: { type: Number, default: 30 },
});

const { branding, appName } = usePlatformBranding();

const light = computed(() => branding.value.app_logo || branding.value.app_logo_icon || '');
const dark = computed(() => branding.value.app_logo_dark || branding.value.app_logo_icon_dark || light.value);
</script>

<template>
    <span v-if="theme === 'dark' && dark" class="inline-flex items-center"><PnLogoImage :src="dark" :alt="appName" :height="height" /></span>
    <span v-else-if="light || dark" class="inline-flex items-center">
        <span v-if="light" :class="dark && dark !== light ? 'flex dark:hidden' : 'flex'"><PnLogoImage :src="light" :alt="appName" :height="height" /></span>
        <span v-if="dark && dark !== light" class="hidden dark:flex"><PnLogoImage :src="dark" :alt="appName" :height="height" /></span>
    </span>
    <span v-else class="pn-wordmark" style="font-size: 20px">{{ appName }}<i aria-hidden="true" /></span>
</template>
