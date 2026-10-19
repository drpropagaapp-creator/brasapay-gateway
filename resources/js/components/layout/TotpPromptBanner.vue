<script setup>
import PnBanner from '@/components/paynext/PnBanner.vue';
import { ref, computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import { Shield, X } from 'lucide-vue-next';

const COOLDOWN_MS = 2 * 24 * 60 * 60 * 1000;

const page = usePage();
const dismissed = ref(false);

const userId = computed(() => page.props.auth?.user?.id ?? null);
const totpEnabled = computed(() => Boolean(page.props.auth?.user?.totp_enabled));
const showTotpPrompt = computed(() => Boolean(page.props.auth?.user?.show_totp_prompt));
const needsKycAttention = computed(() => Boolean(page.props.auth?.user?.needs_kyc_attention));
const isPlatformAdmin = computed(() => Boolean(page.props.auth?.is_platform_admin));

const profileHref = computed(() => (
    isPlatformAdmin.value ? '/plataforma/meu-perfil' : '/meu-perfil'
));

const storageKey = computed(() => (
    userId.value ? `totp_prompt_dismissed_${userId.value}` : null
));

function wasDismissedRecently() {
    if (!storageKey.value || typeof localStorage === 'undefined') {
        return false;
    }
    const raw = localStorage.getItem(storageKey.value);
    if (!raw) {
        return false;
    }
    const ts = parseInt(raw, 10);
    return Number.isFinite(ts) && Date.now() - ts < COOLDOWN_MS;
}

function dismiss() {
    if (storageKey.value && typeof localStorage !== 'undefined') {
        localStorage.setItem(storageKey.value, String(Date.now()));
    }
    dismissed.value = true;
}

const show = computed(() => {
    if (dismissed.value || totpEnabled.value || !userId.value || needsKycAttention.value) {
        return false;
    }
    if (!showTotpPrompt.value) {
        return false;
    }
    return !wasDismissedRecently();
});
</script>

<template>
    <PnBanner
        v-if="show"
        tone="brand"
        :icon="Shield"
        title="Proteja sua conta com autenticação em dois fatores"
        text="Ative o 2FA no seu perfil para exigir um código no login e manter sua conta mais segura."
        dismissible
        @dismiss="dismiss"
    >
        <template #actions>
            <Link :href="profileHref" class="pn-btn pn-btn--primary pn-btn--sm pn-keep-shape no-underline hover:no-underline">Ativar agora</Link>
        </template>
    </PnBanner>
</template>
