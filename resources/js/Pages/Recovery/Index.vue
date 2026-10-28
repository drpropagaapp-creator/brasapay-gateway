<script setup>
import { reactive, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import Button from '@/components/ui/Button.vue';
import { MailCheck, Plus, Trash2 } from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

/**
 * Brasa Recovery — e-mails de recuperação. Venda recuperada só conta com pagamento confirmado
 * depois do e-mail; quem se descadastra ou já comprou o produto não recebe.
 */
const props = defineProps({
    settings: { type: Object, required: true },
    smtp_ready: { type: Boolean, default: false },
    can_edit: { type: Boolean, default: true },
    stats: { type: Object, required: true },
    by_event: { type: [Object, Array], default: () => ({}) },
    recent: { type: Array, default: () => [] },
});

const EVENTS = {
    cart_abandoned: { label: 'Carrinho abandonado', hint: 'Preencheu o checkout e não comprou.' },
    pix_pending: { label: 'PIX não pago', hint: 'Gerou o PIX e não pagou.' },
    boleto_pending: { label: 'Boleto em aberto', hint: 'Gerou o boleto e não pagou.' },
};
const STATUS = { sent: 'Enviado', failed: 'Falhou', skipped: 'Pulado', sending: 'Enviando' };
const SKIP = { opted_out: 'descadastrado', already_bought: 'já comprou' };

const form = reactive(JSON.parse(JSON.stringify(props.settings)));
const saving = ref(false);
const brl = (c) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format((Number(c) || 0) / 100);
const delayLabel = (m) => (m >= 1440 ? `${Math.round(m / 1440)} dia(s)` : m >= 60 ? `${Math.round(m / 60)} h` : `${m} min`);

function addStep(key) {
    const steps = form.events[key].steps;
    if (steps.length >= 3) return;
    const last = steps[steps.length - 1];
    steps.push({ delay_minutes: last ? last.delay_minutes * 2 : 60, subject: '{nome}, ainda dá tempo', body: 'Oi {nome}, {produto} continua disponível:\n\n{link}' });
}
function save() {
    saving.value = true;
    router.put('/recuperacao', form, { preserveScroll: true, onFinish: () => (saving.value = false) });
}
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-semibold"><MailCheck class="h-6 w-6" /> Recuperação de vendas</h1>
            <p class="mt-1 max-w-2xl text-sm text-zinc-500">
                E-mails automáticos para quem abandonou o checkout ou não pagou o PIX/boleto. Respeita horário silencioso,
                limite por dia e descadastro. Só conta como recuperada a venda paga depois do e-mail.
            </p>
        </div>

        <p v-if="!smtp_ready" class="rounded-2xl bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-950/30 dark:text-amber-300">
            Configure o seu e-mail de envio (Configurações → E-mail) para os lembretes saírem. Por segurança, a recuperação nunca usa o e-mail da plataforma.
        </p>

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80"><p class="text-xs text-zinc-500">Vendas recuperadas (30 d)</p><p class="mt-1 text-2xl font-semibold">{{ stats.recovered_orders }}</p></div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80"><p class="text-xs text-zinc-500">Receita recuperada</p><p class="mt-1 text-2xl font-semibold">{{ brl(stats.recovered_amount_cents) }}</p></div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80"><p class="text-xs text-zinc-500">E-mails enviados</p><p class="mt-1 text-2xl font-semibold">{{ stats.sent }}</p><p class="text-xs text-zinc-500">{{ stats.failed }} falhas · {{ stats.skipped }} pulados</p></div>
            <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80"><p class="text-xs text-zinc-500">Descadastros</p><p class="mt-1 text-2xl font-semibold">{{ stats.opt_outs }}</p></div>
        </div>

        <section class="space-y-4 rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900/80">
            <label class="flex items-center gap-2 font-medium"><input v-model="form.enabled" type="checkbox" class="h-4 w-4" /> Recuperação por e-mail ativa</label>
            <div class="grid grid-cols-3 gap-3 text-sm sm:max-w-md">
                <label>Silêncio das<input v-model.number="form.quiet_start" type="number" min="0" max="23" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-800" /></label>
                <label>até as<input v-model.number="form.quiet_end" type="number" min="0" max="23" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-800" /></label>
                <label>Máx. por dia<input v-model.number="form.max_per_day" type="number" min="1" max="5" class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-800" /></label>
            </div>
            <p class="text-xs text-zinc-500">Variáveis: {nome} {produto} {valor} {link}. O link de descadastro entra sempre no rodapé.</p>

            <div v-for="(meta, key) in EVENTS" :key="key" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <label class="flex items-center gap-2 font-medium"><input v-model="form.events[key].enabled" type="checkbox" class="h-4 w-4" /> {{ meta.label }}</label>
                    <span class="text-xs text-zinc-500">{{ meta.hint }} · {{ by_event[key] || 0 }} enviados (30 d)</span>
                </div>
                <div v-for="(step, i) in form.events[key].steps" :key="i" class="mt-3 space-y-2 rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800/60">
                    <div class="flex items-center justify-between gap-2 text-sm">
                        <label class="flex items-center gap-2">Enviar após
                            <input v-model.number="step.delay_minutes" type="number" min="10" max="10080" class="w-24 rounded-lg border border-zinc-200 bg-white px-2 py-1 dark:border-zinc-700 dark:bg-zinc-900" /> min
                            <span class="text-xs text-zinc-500">({{ delayLabel(step.delay_minutes) }})</span>
                        </label>
                        <button type="button" class="rounded-lg p-2 text-zinc-500 hover:text-red-600" aria-label="Remover passo" @click="form.events[key].steps.splice(i, 1)"><Trash2 class="h-4 w-4" /></button>
                    </div>
                    <input v-model="step.subject" maxlength="150" placeholder="Assunto" class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900" />
                    <textarea v-model="step.body" rows="4" maxlength="3000" class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900" />
                </div>
                <button v-if="form.events[key].steps.length < 3" type="button" class="mt-2 inline-flex items-center gap-1 text-sm text-[var(--color-primary)]" @click="addStep(key)"><Plus class="h-4 w-4" /> Adicionar passo</button>
            </div>
            <Button v-if="can_edit" type="button" :disabled="saving" @click="save">Salvar</Button>
            <p v-else class="text-xs text-zinc-500">Só o titular da conta pode alterar os textos.</p>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900/80">
            <h2 class="font-semibold">Últimos envios</h2>
            <p v-if="!recent.length" class="mt-2 text-sm text-zinc-500">Nenhum envio ainda.</p>
            <ul v-else class="mt-2 divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                <li v-for="m in recent" :key="m.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                    <span>{{ EVENTS[m.event]?.label }} · passo {{ m.step + 1 }} · {{ m.recipient }}</span>
                    <span class="text-xs text-zinc-500">
                        {{ STATUS[m.status] || m.status }}<template v-if="m.skip_reason"> ({{ SKIP[m.skip_reason] || m.skip_reason }})</template>
                        <b v-if="m.recovered" class="ml-1 text-emerald-600">recuperado</b>
                        · {{ m.sent_at ? new Date(m.sent_at).toLocaleString('pt-BR') : '' }}
                    </span>
                </li>
            </ul>
        </section>
    </div>
</template>
