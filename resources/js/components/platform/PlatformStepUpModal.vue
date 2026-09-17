<script setup>
import { computed, ref, watch } from 'vue';
import Button from '@/components/ui/Button.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    title: { type: String, default: 'Confirmação de segurança' },
    description: { type: String, default: '' },
    /** When false, hide the 2FA field (platform TOTP not enabled). */
    requireTotp: { type: Boolean, default: true },
    requirePin: { type: Boolean, default: false },
    requireExternalConfirm: { type: Boolean, default: false },
    requireAcquirer: { type: Boolean, default: false },
    acquirers: { type: Array, default: () => [] },
    confirmLabel: { type: String, default: 'Confirmar' },
    loading: { type: Boolean, default: false },
    confirmDisabled: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'confirm']);

const MANUAL_APPROVAL_PIN_MAX_LENGTH = 6;

const totpCode = ref('');
const manualPin = ref('');
const externalConfirm = ref(false);
const selectedAcquirer = ref('');

function sanitizeManualApprovalPinInput(value) {
    return String(value ?? '').replace(/\D/g, '').slice(0, MANUAL_APPROVAL_PIN_MAX_LENGTH);
}

function onManualPinInput(event) {
    manualPin.value = sanitizeManualApprovalPinInput(event.target.value);
}

watch(
    () => props.open,
    (v) => {
        if (v) {
            totpCode.value = '';
            manualPin.value = '';
            externalConfirm.value = false;
            selectedAcquirer.value = '';
        }
    }
);

const submitBlocked = computed(
    () =>
        props.loading ||
        props.confirmDisabled ||
        (props.requireExternalConfirm && !externalConfirm.value) ||
        (props.requireAcquirer && !selectedAcquirer.value)
);

function submit() {
    if (submitBlocked.value) return;
    emit('confirm', {
        totp_code: totpCode.value,
        manual_approval_pin: manualPin.value,
        manual_confirm_external: externalConfirm.value,
        payout_acquirer: selectedAcquirer.value,
    });
}
</script>

<template>
    <div
        v-if="open"
        class="fixed inset-0 z-[100002] flex items-center justify-center bg-black/50 p-4"
        @click.self="emit('close')"
    >
        <div
            class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-xl border border-zinc-200 bg-white p-5 shadow-xl dark:border-zinc-700 dark:bg-zinc-900"
            role="dialog"
            aria-modal="true"
        >
            <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">
                {{ title }}
            </h3>
            <p
                v-if="description"
                class="mt-2 text-sm text-zinc-600 dark:text-zinc-400"
            >
                {{ description }}
            </p>

            <div class="mt-4 space-y-3">
                <div v-if="requireAcquirer">
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300" for="payout-acquirer-select">
                        De onde saiu o PIX?
                    </label>
                    <p class="mb-2 text-xs text-zinc-500 dark:text-zinc-400">
                        Escolha a adquirente para o comprovante. Se o PIX saiu de uma conta fora do sistema, use pagamento por conta externa.
                    </p>
                    <select
                        id="payout-acquirer-select"
                        v-model="selectedAcquirer"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100"
                    >
                        <option value="" disabled>Selecione a origem do pagamento</option>
                        <option
                            v-for="acquirer in acquirers"
                            :key="acquirer.slug"
                            :value="acquirer.slug"
                        >
                            {{ acquirer.name }}
                        </option>
                    </select>
                    <p v-if="!acquirers.length" class="mt-1 text-xs text-red-600 dark:text-red-400">
                        Nenhuma opção carregada. Recarregue a página de saques e tente de novo.
                    </p>
                </div>

                <div v-if="requireTotp">
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        Código 2FA
                    </label>
                    <input
                        v-model="totpCode"
                        type="text"
                        inputmode="numeric"
                        maxlength="6"
                        autocomplete="one-time-code"
                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                        placeholder="000000"
                    />
                </div>

                <div v-if="requirePin">
                    <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                        PIN de operação
                    </label>
                    <input
                        :value="manualPin"
                        type="password"
                        inputmode="numeric"
                        :maxlength="MANUAL_APPROVAL_PIN_MAX_LENGTH"
                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                        placeholder="PIN da plataforma"
                        @input="onManualPinInput"
                    />
                </div>

                <label
                    v-if="requireExternalConfirm"
                    class="flex items-start gap-2 text-sm text-zinc-700 dark:text-zinc-300"
                >
                    <input
                        v-model="externalConfirm"
                        type="checkbox"
                        class="mt-1"
                    />
                    <span>Confirmo que o PIX já foi enviado fora do sistema (aprovação manual).</span>
                </label>
            </div>

            <div class="mt-5 flex justify-end gap-2">
                <Button type="button" variant="secondary" @click="emit('close')">
                    Cancelar
                </Button>
                <Button type="button" :disabled="submitBlocked" @click="submit">
                    {{ confirmLabel }}
                </Button>
            </div>
        </div>
    </div>
</template>
