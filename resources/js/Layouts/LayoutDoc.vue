<script setup>
import { onBeforeUnmount, onMounted } from 'vue';
import { Link } from '@inertiajs/vue3';
import { usePage } from '@inertiajs/vue3';
import { ArrowLeft } from 'lucide-vue-next';
import PlatformBrandLogo from '@/components/branding/PlatformBrandLogo.vue';
import PnAurora from '@/components/paynext/PnAurora.vue';

defineProps({
    showBack: { type: Boolean, default: true },
});

const page = usePage();

const docTitle = () => page.props.pageTitle || 'Documentação da API';
const docSubtitle = () => page.props.docSubtitle || '';
const docsHomeHref = () => (page.props.publicMode ? '/docs/api-pagamentos' : '/aplicacoes-api');

// Paynext DS: a documentação é escrita para o vidro escuro (como a área de membros).
// Na navegação SPA vindo do painel claro, força o escuro e devolve o tema anterior ao sair.
let previousDark = null;
onMounted(() => {
    previousDark = document.documentElement.classList.contains('dark');
    document.documentElement.classList.add('dark');
});
onBeforeUnmount(() => {
    if (previousDark === false) document.documentElement.classList.remove('dark');
});
</script>

<template>
    <div class="pn-shell min-h-screen">
        <PnAurora />
        <header class="sticky top-3 z-20 px-3 sm:px-4">
            <div
                class="pn-glass pn-glass--strong mx-auto flex h-16 max-w-[1600px] items-center justify-between gap-4 rounded-full px-4 sm:px-6"
            >
                <div class="flex min-w-0 flex-1 items-center gap-3 sm:gap-4">
                    <Link :href="docsHomeHref()" class="flex shrink-0 items-center py-1 transition hover:opacity-90" :title="docTitle()">
                        <PlatformBrandLogo theme="dark" :height="28" />
                    </Link>

                    <div class="hidden h-8 w-px shrink-0 bg-[var(--border-default)] sm:block" />

                    <div class="min-w-0 flex-1 sm:flex-none">
                        <p class="truncate text-xs font-medium tracking-tight text-[var(--text-primary)] sm:text-sm">
                            {{ docTitle() }}
                        </p>
                        <p v-if="docSubtitle()" class="hidden truncate text-xs text-[var(--text-tertiary)] sm:block">
                            {{ docSubtitle() }}
                        </p>
                    </div>

                    <Link
                        v-if="showBack && !page.props.publicMode"
                        href="/aplicacoes-api"
                        class="pn-btn pn-btn--secondary pn-btn--sm ml-auto shrink-0 sm:ml-0"
                    >
                        <ArrowLeft class="h-4 w-4" />
                        <span class="hidden md:inline">Voltar ao painel</span>
                    </Link>
                </div>
            </div>
        </header>
        <main class="mx-auto max-w-[1600px] px-4 pt-6 sm:px-6">
            <slot />
        </main>
    </div>
</template>
