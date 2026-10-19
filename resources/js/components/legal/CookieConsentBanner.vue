<script setup>
import { ref, onMounted, computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const STORAGE_KEY = 'cookie_consent_v1';

const page = usePage();
const visible = ref(false);

const legal = computed(() => page.props.legal ?? {});
const privacyUrl = computed(() => legal.value.privacy_url || '/politica-privacidade');

onMounted(() => {
    if (!legal.value.cookie_banner_enabled) {
        return;
    }
    try {
        if (!localStorage.getItem(STORAGE_KEY)) {
            visible.value = true;
        }
    } catch {
        visible.value = true;
    }
});

function saveChoice(value) {
    try {
        localStorage.setItem(STORAGE_KEY, value);
    } catch {
        /* ignore */
    }
    visible.value = false;
    window.dispatchEvent(new CustomEvent('cookie-consent-changed', { detail: value }));
}

function accept() {
    saveChoice('accepted');
}

function rejectNonEssential() {
    saveChoice('essential_only');
}
</script>

<template>
    <div
        v-if="visible"
        class="fixed inset-x-3 bottom-3 z-[9999] sm:inset-x-6 sm:bottom-6"
        role="dialog"
        aria-label="Preferências de cookies"
    >
        <div class="pn-glass pn-glass--strong mx-auto flex max-w-4xl flex-col gap-4 rounded-[28px] p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5">
            <p class="text-sm leading-relaxed text-[var(--text-secondary)]">
                Utilizamos cookies e tecnologias similares para funcionamento, segurança e melhoria da experiência.
                Consulte nossa
                <Link :href="privacyUrl" class="font-medium text-[var(--text-brand)] underline underline-offset-2">
                    Política de Privacidade
                </Link>
                para mais informações.
            </p>
            <div class="flex shrink-0 flex-wrap gap-2">
                <button
                    type="button"
                    class="pn-btn pn-btn--secondary"
                    @click="rejectNonEssential"
                >
                    Recusar não essenciais
                </button>
                <button
                    type="button"
                    class="pn-btn pn-btn--primary"
                    @click="accept"
                >
                    Aceitar
                </button>
            </div>
        </div>
    </div>
</template>
