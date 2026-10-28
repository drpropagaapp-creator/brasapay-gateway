<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import Button from '@/components/ui/Button.vue';
import { Repeat } from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    subscriptions: { type: Array, default: () => [] },
    reasons: { type: Object, required: true },
});

const STATUS = { active: 'Ativa', past_due: 'Pagamento pendente', cancelled: 'Cancelada' };
const INTERVAL = { weekly: 'semana', monthly: 'mês', quarterly: 'trimestre', semi_annual: 'semestre', annual: 'ano', lifetime: 'vitalício' };
const brl = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(v) || 0);
const date = (d) => (d ? new Date(`${d}T12:00:00`).toLocaleDateString('pt-BR') : '—');

const cancelling = ref(null);
const reason = ref('');
const note = ref('');
const busy = ref(false);

function confirmCancel() {
    if (!reason.value) return;
    busy.value = true;
    router.post(`/painel-cliente/assinaturas/${cancelling.value.id}/cancelar`, { reason: reason.value, note: note.value || undefined }, {
        preserveScroll: true,
        onSuccess: () => (cancelling.value = null),
        onFinish: () => (busy.value = false),
    });
}
function reactivate(s) {
    router.post(`/painel-cliente/assinaturas/${s.id}/reativar`, {}, { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-5">
        <div>
            <Link href="/painel-cliente" class="text-sm text-zinc-500 hover:underline">← Minhas compras</Link>
            <h1 class="mt-1 flex items-center gap-2 text-2xl font-semibold"><Repeat class="h-6 w-6" /> Minhas assinaturas</h1>
        </div>

        <p v-if="!subscriptions.length" class="rounded-2xl border border-dashed border-zinc-300 py-10 text-center text-sm text-zinc-500 dark:border-zinc-700">Você não tem assinaturas.</p>

        <ul class="space-y-3">
            <li v-for="s in subscriptions" :key="s.id" class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-medium">{{ s.product }}</p>
                        <p class="text-sm text-zinc-500">{{ s.plan }} · {{ brl(s.price) }} / {{ INTERVAL[s.interval] || s.interval }}</p>
                        <p class="mt-1 text-sm">
                            {{ STATUS[s.status] || s.status }}
                            <template v-if="s.status !== 'cancelled'"> · acesso até {{ date(s.current_period_end) }}</template>
                        </p>
                        <p v-if="s.cancel_at_period_end" class="mt-1 text-sm text-amber-700 dark:text-amber-400">Renovação cancelada — termina em {{ date(s.current_period_end) }}.</p>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <a v-if="s.status !== 'cancelled' && s.renewal_url" :href="s.renewal_url" class="text-sm text-[var(--color-primary)] hover:underline">Pagar / trocar forma de pagamento</a>
                        <Button v-if="s.status !== 'cancelled' && !s.cancel_at_period_end" size="sm" variant="outline" @click="cancelling = s; reason = ''; note = ''">Cancelar renovação</Button>
                        <Button v-if="s.cancel_at_period_end && s.status === 'active' && !s.pix_auto" size="sm" variant="outline" @click="reactivate(s)">Manter assinatura</Button>
                    </div>
                </div>
            </li>
        </ul>

        <div v-if="cancelling" class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 p-4 sm:items-center" @click.self="cancelling = null">
            <div class="w-full max-w-md rounded-2xl bg-white p-5 dark:bg-zinc-900">
                <p class="font-semibold">Cancelar a renovação de {{ cancelling.product }}?</p>
                <p class="mt-1 text-sm text-zinc-500">Você mantém o acesso até {{ date(cancelling.current_period_end) }} e não será cobrado de novo.</p>
                <p class="mt-3 text-sm font-medium">Por que está cancelando?</p>
                <div class="mt-1 space-y-1">
                    <label v-for="(label, key) in reasons" :key="key" class="flex items-center gap-2 text-sm"><input v-model="reason" type="radio" :value="key" /> {{ label }}</label>
                </div>
                <textarea v-model="note" rows="2" maxlength="1000" placeholder="Quer contar mais? (opcional)" class="mt-2 w-full rounded-xl border border-zinc-200 bg-white p-2 text-sm dark:border-zinc-700 dark:bg-zinc-800" />
                <div class="mt-3 flex justify-end gap-2">
                    <Button variant="ghost" @click="cancelling = null">Voltar</Button>
                    <Button variant="destructive" :disabled="!reason || busy" @click="confirmCancel">Cancelar renovação</Button>
                </div>
            </div>
        </div>
    </div>
</template>
