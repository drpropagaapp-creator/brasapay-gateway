<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import CookieConsentBanner from '@/components/legal/CookieConsentBanner.vue';
import PnAurora from '@/components/paynext/PnAurora.vue';
import PnWordmark from '@/components/paynext/PnWordmark.vue';

const page = usePage();
const isActive = (href) => page.url.startsWith(href);
</script>

<template>
    <div class="pn-shell min-h-screen">
        <PnAurora />
        <header class="px-3 pt-3 sm:px-4">
            <div class="pn-glass pn-glass--strong mx-auto flex h-16 max-w-3xl items-center justify-between gap-4 rounded-full px-4 sm:px-6">
                <Link href="/" class="flex items-center">
                    <PnWordmark />
                </Link>
                <nav class="pn-tabs" aria-label="Documentos legais">
                    <Link href="/termos-de-uso" class="pn-tab" :class="{ 'pn-tab--active': isActive('/termos-de-uso') }">
                        Termos
                    </Link>
                    <Link
                        href="/politica-privacidade"
                        class="pn-tab"
                        :class="{ 'pn-tab--active': isActive('/politica-privacidade') }"
                    >
                        Privacidade
                    </Link>
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-3xl px-4 py-8 sm:px-6">
            <div class="pn-glass pn-glass--flat pn-anim-soft rounded-[28px] p-6 sm:p-10">
                <slot />
            </div>
        </main>

        <CookieConsentBanner />
    </div>
</template>
