<script setup>
import { Moon, Sun } from 'lucide-vue-next';
import { usePanelColorScheme } from '@/composables/usePanelColorScheme';

/** Botão sol/lua do Paynext DS: alterna vidro escuro (aurora) e vidro claro (céu). */
defineProps({
    tone: { type: String, default: 'default' },
    size: { type: String, default: 'md' },
});

const { theme, showToggler, setTheme } = usePanelColorScheme();
</script>

<template>
    <button
        v-if="showToggler"
        type="button"
        class="pn-iconbtn pn-keep-shape"
        :style="size === 'sm' ? 'width:38px;height:38px' : ''"
        :aria-label="theme === 'dark' ? 'Ativar tema claro' : 'Ativar tema escuro'"
        :title="theme === 'dark' ? 'Tema claro' : 'Tema escuro'"
        @click="setTheme(theme === 'dark' ? 'light' : 'dark')"
    >
        <Sun v-if="theme === 'dark'" class="h-5 w-5" />
        <Moon v-else class="h-5 w-5" />
    </button>
</template>
