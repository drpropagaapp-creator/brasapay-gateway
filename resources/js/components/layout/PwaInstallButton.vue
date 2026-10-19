<script setup>
import { ref, computed, onMounted } from 'vue';
import { Download, Smartphone } from 'lucide-vue-next';
import { usePwaInstall } from '@/composables/usePwaInstall';

const props = defineProps({
    /** menu = item simples; banner = destaque no sidebar mobile */
    variant: {
        type: String,
        default: 'menu',
        validator: (v) => ['menu', 'banner'].includes(v),
    },
    /** Mantido por compatibilidade; o visual é sempre o do Paynext DS. */
    theme: { type: String, default: 'default' },
});

const {
    canShowInstallButton,
    isIos,
    isMobile,
    isSecureContextForPwa,
    canTriggerNativeInstallPrompt,
    triggerInstall,
    registerListener,
    syncInstallPromptFromWindow,
} = usePwaInstall('painel');

const showFallbackMessage = ref(false);
let fallbackTimer = null;

const isAndroid = computed(() => isMobile.value && !isIos.value);
const showHttpsWarning = computed(() => showFallbackMessage.value && isAndroid.value && !isSecureContextForPwa.value);

async function handleInstallClick() {
    showFallbackMessage.value = false;
    if (fallbackTimer) {
        clearTimeout(fallbackTimer);
        fallbackTimer = null;
    }
    syncInstallPromptFromWindow();
    if (canTriggerNativeInstallPrompt.value) {
        await triggerInstall();
        return;
    }
    if (isIos.value) {
        triggerInstall();
        return;
    }
    showFallbackMessage.value = true;
    fallbackTimer = setTimeout(() => {
        showFallbackMessage.value = false;
        fallbackTimer = null;
    }, 8000);
}

onMounted(() => {
    registerListener();
});
</script>

<template>
    <div v-if="canShowInstallButton" class="space-y-1.5">
        <button
            v-if="variant === 'banner'"
            type="button"
            class="pn-sidebar__promo pn-keep-shape w-full cursor-pointer border-0 text-left"
            @click="handleInstallClick"
        >
            <span class="flex w-full items-center gap-3">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-[var(--ink-900)] text-[var(--lime-300)]">
                    <Smartphone class="h-5 w-5" weight="fill" />
                </span>
                <span class="min-w-0 flex-1">
                    <b class="flex items-center gap-1.5">Instalar app <Download class="h-3.5 w-3.5" /></b>
                    <p class="!mb-0">Acesso rápido na tela inicial do celular</p>
                </span>
            </span>
        </button>

        <button
            v-else
            type="button"
            class="pn-nav pn-keep-shape"
            @click="handleInstallClick"
        >
            <Smartphone class="h-5 w-5 shrink-0" />
            <span class="pn-nav__label truncate">Instalar app</span>
        </button>

        <p
            v-if="showHttpsWarning"
            class="px-1 text-xs font-light text-[var(--text-secondary)]"
        >
            Para instalar como app no Android, abra em <strong>HTTPS</strong> (cadeado na barra). Em HTTP, o Chrome cria apenas atalho e não exibe o prompt nativo de instalação.
        </p>
        <p
            v-else-if="showFallbackMessage && isAndroid"
            class="px-1 text-xs font-light text-[var(--text-secondary)]"
        >
            No Chrome, toque no menu (⋮) e escolha <strong>Instalar app</strong>. Se aparecer apenas <strong>Adicionar à tela inicial</strong>, o navegador ainda não considerou a página instalável.
        </p>
        <p
            v-else-if="showFallbackMessage"
            class="px-1 text-xs font-light text-[var(--text-secondary)]"
        >
            Use o menu (⋮) do navegador e escolha <strong>Instalar app</strong> ou <strong>Adicionar à tela inicial</strong> para colocar o app na sua tela inicial.
        </p>
    </div>
</template>
