<script setup>
import { ref } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { Eye, EyeOff, Mail, Lock } from 'lucide-vue-next';
import Button from '@/components/ui/Button.vue';
import AuthPageShell from '@/components/auth/AuthPageShell.vue';
import { useAuthFormStyles } from '@/composables/useAuthFormStyles';

const props = defineProps({
    token: { type: String, required: true },
    email: { type: String, default: '' },
    redirect: { type: String, default: '' },
});

const showPassword = ref(false);
const showPasswordConfirmation = ref(false);

const {
    primary,
    inputClass,
    labelClass,
    linkClass,
    mutedTextClass,
    submitButtonClass,
} = useAuthFormStyles();

const form = useForm({
    token: props.token,
    email: props.email || '',
    password: '',
    password_confirmation: '',
    redirect: props.redirect || '',
});

function submit() {
    form.post('/redefinir-senha', {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <AuthPageShell
        title="Redefinir senha"
        subtitle="Digite sua nova senha abaixo."
        variant="neutral"
    >
        <form class="space-y-5" @submit.prevent="submit">
            <input v-model="form.token" type="hidden" name="token" />
            <input v-if="form.redirect" v-model="form.redirect" type="hidden" name="redirect" />



                            <div>
                    <label for="email" :class="labelClass">E-mail</label>
                    <input id="email" v-model="form.email" type="email" autocomplete="username" required :class="inputClass" placeholder="seu@email.com" />
                    <p v-if="form.errors.email" class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ form.errors.email }}</p>
                </div>
                <div>
                    <label for="password" :class="labelClass">Nova senha</label>
                    <div class="relative mt-1.5">
                        <input
                            id="password"
                            v-model="form.password"
                            :type="showPassword ? 'text' : 'password'"
                            autocomplete="new-password"
                            required
                            class="wl-input block w-full rounded-xl border border-zinc-300 bg-white py-3 pl-4 pr-12 text-zinc-900 shadow-sm transition dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                            placeholder="••••••••"
                        />
                        <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-500" @click="showPassword = !showPassword">
                            <Eye v-if="showPassword" class="h-5 w-5" />
                            <EyeOff class="h-5 w-5" />
                        </button>
                    </div>
                    <p v-if="form.errors.password" class="mt-1.5 text-sm text-red-600 dark:text-red-400">{{ form.errors.password }}</p>
                </div>
                <div>
                    <label for="password_confirmation" :class="labelClass">Confirmar senha</label>
                    <div class="relative mt-1.5">
                        <input
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            :type="showPasswordConfirmation ? 'text' : 'password'"
                            autocomplete="new-password"
                            required
                            class="wl-input block w-full rounded-xl border border-zinc-300 bg-white py-3 pl-4 pr-12 text-zinc-900 shadow-sm transition dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                            placeholder="••••••••"
                        />
                        <button type="button" class="absolute right-3 top-1/2 -translate-y-1/2 text-zinc-500" @click="showPasswordConfirmation = !showPasswordConfirmation">
                            <Eye v-if="showPasswordConfirmation" class="h-5 w-5" />
                            <EyeOff class="h-5 w-5" />
                        </button>
                    </div>
                    <p v-if="form.errors.password_confirmation" class="mt-1.5 text-sm text-red-600 dark:text-red-400">
                        {{ form.errors.password_confirmation }}
                    </p>
                </div>

            <Button type="submit" :class="submitButtonClass" :disabled="form.processing">
                {{ form.processing ? 'Redefinindo…' : 'Redefinir senha' }}
            </Button>
        </form>

        <p :class="['mt-6 text-center', mutedTextClass]">
            <Link :href="props.redirect || '/login'" :class="linkClass">Voltar ao login</Link>
        </p>
    </AuthPageShell>
</template>

<style scoped>
.wl-input:focus {
    border-color: v-bind(primary);
    outline: none;
    box-shadow: 0 0 0 2px color-mix(in srgb, v-bind(primary) 35%, transparent);
}
.wl-submit {
    background-color: v-bind(primary) !important;
    color: #18181b !important;
}
</style>
