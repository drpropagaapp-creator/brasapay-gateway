<script setup>
import { computed } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import { Shield, ArrowRight } from 'lucide-vue-next';
import Button from '@/components/ui/Button.vue';
import AuthPageShell from '@/components/auth/AuthPageShell.vue';
import { useAuthFormStyles } from '@/composables/useAuthFormStyles';

const props = defineProps({
    submitUrl: { type: String, required: true },
    cancelUrl: { type: String, required: true },
    title: { type: String, default: 'Verificação em dois fatores' },
});

const page = usePage();
const flashError = computed(() => page.props.flash?.error ?? null);

const {
    primary,
    inputClass,
    labelClass,
    linkClass,
    alertErrorClass,
    submitButtonClass,
} = useAuthFormStyles();

const form = useForm({
    totp_code: '',
});

function submit() {
    form.post(props.submitUrl, {
        preserveScroll: true,
    });
}

function cancel() {
    form.post(props.cancelUrl);
}
</script>

<template>
    <AuthPageShell
        :title="title"
        subtitle="Digite o código de 6 dígitos do seu aplicativo autenticador."
        variant="neutral"
    >

        <p v-if="flashError" :class="['mb-4', alertErrorClass]">
            {{ flashError }}
        </p>

        <form class="space-y-5" @submit.prevent="submit">
                            <div>
                    <label for="totp_code" :class="labelClass">Código 2FA</label>
                    <input
                        id="totp_code"
                        v-model="form.totp_code"
                        type="text"
                        inputmode="numeric"
                        maxlength="6"
                        autocomplete="one-time-code"
                        required
                        :class="[inputClass, 'text-center text-lg tracking-[0.35em]']"
                        placeholder="000000"
                    />
                    <p v-if="form.errors.totp_code" class="mt-1.5 text-sm text-red-600 dark:text-red-400">
                        {{ form.errors.totp_code }}
                    </p>
                </div>

            <Button
                type="submit"
                :class="submitButtonClass"
                :disabled="form.processing"
            >
                {{ form.processing ? 'Verificando…' : 'Confirmar e entrar' }}
            </Button>
            <button
                type="submit"
                :class="submitButtonClass"
                :disabled="form.processing"
            >
                <span class="inline-flex items-center justify-center gap-2">
                    {{ form.processing ? 'Verificando…' : 'Confirmar e entrar' }}
                    <ArrowRight v-if="!form.processing" class="h-4 w-4" />
                </span>
            </button>
        </form>

        <p class="mt-6 text-center text-sm font-light text-[var(--text-secondary)]">
            <button type="button" :class="linkClass" @click="cancel">
                Voltar ao login
            </button>
        </p>
    </AuthPageShell>
</template>

<style scoped>
.wl-submit {
    background-color: var(--wl-primary) !important;
    color: #18181b !important;
}
</style>
