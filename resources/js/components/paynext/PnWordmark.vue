<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import PnLogoImage from '@/components/paynext/PnLogoImage.vue';

/**
 * Marca no padrão do wordmark Paynext: usa o nome/logo configurados em
 * Plataforma → Branding. Sem logo, desenha o nome em Lexend com o ponto lime
 * pulsando; nomes compostos ("BrasaPay") destacam a segunda parte, como "pay·next".
 */
const props = defineProps({
    /** full = logo/nome; icon = só o símbolo (rail recolhido) */
    variant: { type: String, default: 'full' },
    size: { type: String, default: 'md' },
});

const page = usePage();
/* Logado: appSettings; telas de login/cadastro: public_branding (mesmos campos do admin). */
const pick = (key) => page.props.appSettings?.[key] || page.props.public_branding?.[key] || null;
const name = computed(() => String(pick('app_name') || 'Paynext').trim());

const logo = computed(() => pick('app_logo'));
const logoDark = computed(() => pick('app_logo_dark'));
const icon = computed(() => pick('app_logo_icon'));
const iconDark = computed(() => pick('app_logo_icon_dark'));

const parts = computed(() => {
    const match = name.value.match(/^(.+?[a-zà-ú])([A-ZÀ-Ú].*)$/);
    return match ? [match[1], match[2]] : [name.value, ''];
});
const initial = computed(() => name.value.charAt(0).toUpperCase());
const logoHeight = computed(() => (props.size === 'lg' ? 38 : props.size === 'sm' ? 24 : 30));
const fontSize = computed(() => (props.size === 'lg' ? '28px' : props.size === 'sm' ? '18px' : '22px'));
</script>

<template>
    <span v-if="variant === 'icon'" class="inline-flex items-center justify-center">
        <template v-if="icon || iconDark">
            <span v-if="icon" class="items-center" :class="iconDark ? 'flex dark:hidden' : 'flex'"><PnLogoImage :src="icon" :alt="name" :height="30" :max-width="52" :max-aspect="1.8"><span class="pn-avatar pn-avatar--ink" style="width: 44px; height: 44px; font-size: 18px">{{ initial }}</span></PnLogoImage></span>
            <span v-if="iconDark" class="hidden items-center dark:flex"><PnLogoImage :src="iconDark" :alt="name" :height="30" :max-width="52" :max-aspect="1.8"><span class="pn-avatar pn-avatar--ink" style="width: 44px; height: 44px; font-size: 18px">{{ initial }}</span></PnLogoImage></span>
        </template>
        <span v-else class="pn-avatar pn-avatar--ink" style="width: 44px; height: 44px; font-size: 18px">{{ initial }}</span>
    </span>
    <span v-else class="inline-flex min-w-0 items-center">
        <template v-if="logo || logoDark">
            <span v-if="logo" class="items-center" :class="logoDark ? 'flex dark:hidden' : 'flex'"><PnLogoImage :src="logo" :alt="name" :height="logoHeight" /></span>
            <span v-if="logoDark" class="hidden items-center dark:flex"><PnLogoImage :src="logoDark" :alt="name" :height="logoHeight" /></span>
        </template>
        <span v-else class="pn-wordmark truncate" :style="{ fontSize }">
            <span class="truncate">{{ parts[0] }}<b v-if="parts[1]">{{ parts[1] }}</b></span>
            <i aria-hidden="true" />
        </span>
    </span>
</template>
