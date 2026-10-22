<script setup>
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { X } from 'lucide-vue-next';
import Button from '@/components/ui/Button.vue';

const props = defineProps({
    offer: { type: Object, default: null },
});

const checkoutOpen = ref(false);
const dismissed = ref(false);

const storageKey = computed(() => {
    const id = props.offer?.product_id;
    return id ? `ma-lesson-offer:${id}` : '';
});

function readDismissed() {
    if (!storageKey.value || typeof sessionStorage === 'undefined') {
        dismissed.value = false;
        return;
    }
    try {
        dismissed.value = sessionStorage.getItem(storageKey.value) === '1';
    } catch (_) {
        dismissed.value = false;
    }
}

const visible = computed(() => Boolean(props.offer?.checkout_url) && !dismissed.value);

const priceLabel = computed(() => {
    const n = Number(props.offer?.price);
    if (!Number.isFinite(n)) return '';
    return n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
});

function dismiss() {
    dismissed.value = true;
    checkoutOpen.value = false;
    if (!storageKey.value) return;
    try {
        sessionStorage.setItem(storageKey.value, '1');
    } catch (_) {}
}

function openCheckout() {
    checkoutOpen.value = true;
}

function closeCheckout() {
    checkoutOpen.value = false;
    router.reload({
        only: ['lesson_offer'],
        preserveScroll: true,
        preserveState: true,
    });
}

function onKeydown(event) {
    if (event.key === 'Escape' && checkoutOpen.value) {
        event.preventDefault();
        closeCheckout();
    }
}

watch(() => props.offer?.product_id, () => {
    readDismissed();
    checkoutOpen.value = false;
});

onMounted(() => {
    readDismissed();
    window.addEventListener('keydown', onKeydown);
});

onUnmounted(() => {
    window.removeEventListener('keydown', onKeydown);
});
</script>

<template>
    <div v-if="visible" class="border-b border-zinc-700 p-3">
        <div class="relative overflow-hidden rounded-lg border border-[var(--ma-primary)]/30 bg-zinc-900/70">
            <button
                type="button"
                class="absolute right-1.5 top-1.5 z-10 rounded-md p-1 text-zinc-400 transition hover:bg-zinc-800 hover:text-white"
                aria-label="Ocultar oferta"
                @click="dismiss"
            >
                <X class="h-3.5 w-3.5" />
            </button>
            <div v-if="offer.image_url" class="aspect-video bg-zinc-800">
                <img :src="offer.image_url" :alt="offer.name" class="h-full w-full object-cover" />
            </div>
            <div class="space-y-2 p-3 pr-8">
                <p class="text-[11px] font-medium uppercase tracking-wide text-[var(--ma-primary)]">Oferta</p>
                <p class="text-sm font-semibold leading-snug text-zinc-100">{{ offer.name }}</p>
                <p v-if="priceLabel" class="text-sm font-medium text-zinc-300">{{ priceLabel }}</p>
                <Button type="button" size="sm" class="w-full touch-manipulation" @click="openCheckout">
                    {{ offer.button_text || 'Comprar' }}
                </Button>
            </div>
        </div>
    </div>

    <Teleport to="body">
        <div
            v-if="checkoutOpen && offer?.checkout_url"
            class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4"
            @click.self="closeCheckout"
        >
            <div class="flex h-[min(90vh,760px)] w-full max-w-lg flex-col overflow-hidden rounded-xl bg-zinc-950 shadow-2xl">
                <div class="flex items-center justify-between gap-3 border-b border-zinc-800 px-4 py-3">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-semibold text-zinc-100">{{ offer.name }}</p>
                        <a
                            :href="offer.checkout_url"
                            target="_blank"
                            rel="noopener"
                            class="text-xs text-zinc-400 hover:text-[var(--ma-primary)] hover:underline"
                        >
                            Problemas para pagar? Abrir checkout
                        </a>
                    </div>
                    <button
                        type="button"
                        class="rounded-md p-1.5 text-zinc-400 transition hover:bg-zinc-800 hover:text-white"
                        aria-label="Fechar checkout"
                        @click="closeCheckout"
                    >
                        <X class="h-5 w-5" />
                    </button>
                </div>
                <iframe
                    :src="offer.checkout_url"
                    title="Checkout"
                    class="min-h-0 w-full flex-1 bg-white"
                    allow="payment; clipboard-write; fullscreen"
                    referrerpolicy="origin-when-cross-origin"
                />
            </div>
        </div>
    </Teleport>
</template>
