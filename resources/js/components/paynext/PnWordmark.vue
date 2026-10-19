<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

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
const settings = computed(() => page.props.appSettings ?? {});
const name = computed(() => String(settings.value.app_name || page.props.public_branding?.app_name || 'Paynext').trim());

const logo = computed(() => settings.value.app_logo || null);
const logoDark = computed(() => settings.value.app_logo_dark || null);
const icon = computed(() => settings.value.app_logo_icon || null);
const iconDark = computed(() => settings.value.app_logo_icon_dark || null);

const parts = computed(() => {
    const match = name.value.match(/^(.+?[a-zà-ú])([A-ZÀ-Ú].*)$/);
    return match ? [match[1], match[2]] : [name.value, ''];
});
const initial = computed(() => name.value.charAt(0).toUpperCase());
const fontSize = computed(() => (props.size === 'lg' ? '28px' : props.size === 'sm' ? '18px' : '22px'));
</script>

<template>
    <span v-if="variant === 'icon'" class="inline-flex items-center justify-center">
        <template v-if="icon || iconDark">
            <img v-if="icon" :src="icon" :alt="name" class="h-10 w-10 object-contain" :class="iconDark ? 'dark:hidden' : ''" />
            <img v-if="iconDark" :src="iconDark" :alt="name" class="hidden h-10 w-10 object-contain dark:block" />
        </template>
        <span v-else class="pn-avatar pn-avatar--ink" style="width: 44px; height: 44px; font-size: 18px">{{ initial }}</span>
    </span>
    <span v-else class="inline-flex min-w-0 items-center">
        <template v-if="logo || logoDark">
            <img v-if="logo" :src="logo" :alt="name" class="h-9 max-w-[190px] object-contain object-left" :class="logoDark ? 'dark:hidden' : ''" />
            <img v-if="logoDark" :src="logoDark" :alt="name" class="hidden h-9 max-w-[190px] object-contain object-left dark:block" />
        </template>
        <span v-else class="pn-wordmark truncate" :style="{ fontSize }">
            <span class="truncate">{{ parts[0] }}<b v-if="parts[1]">{{ parts[1] }}</b></span>
            <i aria-hidden="true" />
        </span>
    </span>
</template>
