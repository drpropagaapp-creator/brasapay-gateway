<script setup>
import PnBanner from '@/components/paynext/PnBanner.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { ShieldAlert } from 'lucide-vue-next';

const page = usePage();

const kycStatus = computed(() => page.props.auth?.user?.kyc_status ?? null);
const kycOnboardingState = computed(() => page.props.auth?.user?.kyc_onboarding_state ?? null);

/** Aviso enquanto conta não estiver operacionalmente aprovada. */
const show = computed(() => Boolean(page.props.auth?.user?.needs_kyc_attention));

const message = computed(() => {
    if (kycOnboardingState.value === 'awaiting_review' || kycStatus.value === 'pending_review') {
        return 'Seus documentos estão em análise. O painel completo, vendas e saques serão liberados após aprovação da plataforma.';
    }
    if (kycStatus.value === 'rejected') {
        return 'Sua verificação precisa de ajustes. Reenvie os documentos na aba Seus dados (Financeiro).';
    }
    if (kycOnboardingState.value === 'pending_account') {
        return 'Sua conta ainda não foi aprovada pela plataforma. Envie os documentos ou aguarde a análise.';
    }
    return 'Complete a verificação de identidade (KYC) para acessar o painel do infoprodutor.';
});

const financeiroKycHref = '/financeiro?tab=seus-dados';
</script>

<template>
    <PnBanner v-if="show" tone="warning" :icon="ShieldAlert" :title="message">
        <template #actions>
            <Link :href="financeiroKycHref" class="pn-btn pn-btn--primary pn-btn--sm pn-keep-shape no-underline hover:no-underline">
                {{ kycStatus === 'pending_review' ? 'Ver status' : 'Enviar documentos' }}
            </Link>
        </template>
    </PnBanner>
</template>
