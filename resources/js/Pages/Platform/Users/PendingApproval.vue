<script setup>
import { Link, router } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import { Clock } from 'lucide-vue-next';

defineOptions({ layout: LayoutPlatform });

defineProps({
    users: { type: Object, required: true },
    kyc_enforcement: { type: String, default: 'onboarding' },
});

const kycLabels = {
    not_submitted: 'Sem documentos',
    pending_review: 'Em análise',
    approved: 'Aprovado',
    rejected: 'Rejeitado',
    blocked: 'Bloqueado',
};

function kycLabel(status) {
    return kycLabels[status] || status || '—';
}

function approve(id) {
    router.post(`/plataforma/usuarios/${id}/aprovacao-basica`, {}, { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="flex items-center gap-2 text-xl font-semibold text-zinc-900 dark:text-white">
                <Clock class="h-6 w-6 text-[var(--color-primary)]" />
                Aguardando aprovação
            </h1>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Contas de infoprodutor ainda pendentes. Aprovar libera criar e vender. O saque continua exigindo KYC enviado e aprovado.
            </p>
        </div>

        <div
            v-if="kyc_enforcement !== 'withdrawal'"
            class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-100"
        >
            O KYC está sendo pedido no cadastro. Aprovar aqui muda o status da conta, mas vender só libera depois da aprovação dos documentos.
        </div>
        <div
            v-else
            class="rounded-lg border border-zinc-200 bg-zinc-50 px-4 py-3 text-sm text-zinc-700 dark:border-zinc-700 dark:bg-zinc-900/40 dark:text-zinc-200"
        >
            Modo atual: KYC só no saque. Cada conta desta fila precisa desta aprovação antes de operar.
        </div>

        <div v-if="users.data?.length" class="space-y-3 lg:hidden">
            <article
                v-for="u in users.data"
                :key="`mobile-${u.id}`"
                class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900/40"
            >
                <p class="break-words text-sm font-semibold text-zinc-900 dark:text-white">{{ u.name }}</p>
                <p class="mt-0.5 break-all text-xs text-zinc-500 dark:text-zinc-400">{{ u.email }}</p>
                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400">Cadastro {{ u.created_at || '—' }}</p>
                <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">KYC: {{ kycLabel(u.kyc_status) }}</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <Link
                        :href="`/plataforma/usuarios/${u.id}`"
                        class="rounded-lg border border-zinc-200 px-3 py-1.5 text-xs font-medium text-zinc-700 dark:border-zinc-600 dark:text-zinc-200"
                    >
                        Ver conta
                    </Link>
                    <button
                        type="button"
                        class="rounded-lg bg-[var(--color-primary)] px-3 py-1.5 text-xs font-medium text-white"
                        @click="approve(u.id)"
                    >
                        Aprovar
                    </button>
                </div>
            </article>
        </div>

        <div v-if="users.data?.length" class="hidden overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900/40 lg:block">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b border-zinc-200 text-xs uppercase text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                    <tr>
                        <th class="px-4 py-3 font-medium">Infoprodutor</th>
                        <th class="px-4 py-3 font-medium">Cadastro</th>
                        <th class="px-4 py-3 font-medium">KYC</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="u in users.data"
                        :key="u.id"
                        class="border-b border-zinc-100 last:border-0 dark:border-zinc-800"
                    >
                        <td class="px-4 py-3">
                            <p class="font-medium text-zinc-900 dark:text-white">{{ u.name }}</p>
                            <p class="break-all text-xs text-zinc-500 dark:text-zinc-400">{{ u.email }}</p>
                        </td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ u.created_at || '—' }}</td>
                        <td class="px-4 py-3 text-zinc-600 dark:text-zinc-300">{{ kycLabel(u.kyc_status) }}</td>
                        <td class="px-4 py-3">
                            <div class="flex justify-end gap-2">
                                <Link
                                    :href="`/plataforma/usuarios/${u.id}`"
                                    class="rounded-lg border border-zinc-200 px-3 py-1.5 text-xs font-medium text-zinc-700 dark:border-zinc-600 dark:text-zinc-200"
                                >
                                    Ver conta
                                </Link>
                                <button
                                    type="button"
                                    class="rounded-lg bg-[var(--color-primary)] px-3 py-1.5 text-xs font-medium text-white"
                                    @click="approve(u.id)"
                                >
                                    Aprovar
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p
            v-else
            class="rounded-2xl border border-dashed border-zinc-300 px-4 py-10 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400"
        >
            Nenhuma conta aguardando aprovação.
        </p>

        <div v-if="users.last_page > 1" class="flex items-center justify-between text-sm">
            <Link
                v-if="users.prev_page_url"
                :href="users.prev_page_url"
                class="text-zinc-600 hover:text-zinc-900 dark:text-zinc-300"
            >
                Anterior
            </Link>
            <span v-else></span>
            <Link
                v-if="users.next_page_url"
                :href="users.next_page_url"
                class="text-zinc-600 hover:text-zinc-900 dark:text-zinc-300"
            >
                Próxima
            </Link>
        </div>
    </div>
</template>
