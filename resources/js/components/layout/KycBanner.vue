<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { ShieldAlert } from 'lucide-vue-next';

const page = usePage();

const kycStatus = computed(() => page.props.auth?.user?.kyc_status ?? null);
const kycOnboardingState = computed(() => page.props.auth?.user?.kyc_onboarding_state ?? null);

/** Aviso enquanto conta não estiver operacionalmente aprovada. */
const show = computed(() => Boolean(page.props.auth?.user?.needs_kyc_attention));

const message = computed(() => {
    if (kycOnboardingState.value === 'pending_basic_approval') {
        return 'Sua conta aguarda aprovação da plataforma para começar a vender. O KYC será pedido no saque.';
    }
    if (kycOnboardingState.value === 'kyc_for_withdrawal') {
        if (kycStatus.value === 'pending_review') {
            return 'Documentos em análise. Você já pode vender; o saque libera depois da aprovação.';
        }
        if (kycStatus.value === 'rejected') {
            return 'O KYC foi recusado. Reenvie os documentos para liberar o saque. Vender continua permitido.';
        }
        return 'Você já pode criar e vender. O saque só libera depois do envio e da aprovação do KYC.';
    }
    if (kycOnboardingState.value === 'awaiting_review' || kycStatus.value === 'pending_review') {
        return 'Seus documentos estão em análise. O painel completo, vendas e saques serão liberados após aprovação da plataforma.';
    }
    if (kycOnboardingState.value === 'needs_document_upgrade') {
        return 'Sua conta foi criada antes da verificação atual. Envie os documentos na aba Seus dados para liberar o saque depois da aprovação.';
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
    <div
        v-if="show"
        class="border-b border-amber-200/80 bg-amber-50 px-4 py-3 dark:border-amber-900/50 dark:bg-amber-950/40"
    >
        <div class="mx-auto flex max-w-[1600px] flex-wrap items-center justify-center gap-3 text-center sm:text-left">
            <ShieldAlert class="h-5 w-5 shrink-0 text-amber-700 dark:text-amber-400" aria-hidden="true" />
            <p class="text-sm text-amber-950 dark:text-amber-100">
                {{ message }}
            </p>
            <Link
                :href="financeiroKycHref"
                class="shrink-0 rounded-lg bg-amber-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-amber-700 dark:bg-amber-700 dark:hover:bg-amber-600"
            >
                {{ kycStatus === 'pending_review' ? 'Ver status' : 'Enviar documentos' }}
            </Link>
        </div>
    </div>
</template>
