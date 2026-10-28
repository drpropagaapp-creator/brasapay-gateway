<script setup>
import { reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import Button from '@/components/ui/Button.vue';
import { Lightbulb, Trash2 } from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

/**
 * Brasa Intelligence — receita só de pagamentos confirmados; ROAS/CAC só onde há custo informado.
 */
const props = defineProps({
    days: { type: Number, required: true },
    periods: { type: Array, default: () => [7, 30, 90] },
    report: { type: Object, required: true },
    can_edit_spend: { type: Boolean, default: false },
    spends: { type: Array, default: () => [] },
});

const brl = (cents) => (cents === null || cents === undefined ? '—' : new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(cents / 100));
const pct = (v) => (v === null || v === undefined ? '—' : `${(v * 100).toFixed(1)}%`);
const roas = (v) => (v === null || v === undefined ? '—' : `${String(v).replace('.', ',')}x`);
const METHOD = { pix: 'Pix', card: 'Cartão', credit_card: 'Cartão', boleto: 'Boleto', pix_auto: 'Pix Automático', paypal: 'PayPal', open_finance: 'Open Finance', outro: 'Outro' };
const LEVEL = {
    danger: 'border-red-200 bg-red-50 text-red-900 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-200',
    warning: 'border-amber-200 bg-amber-50 text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200',
    info: 'border-zinc-200 bg-zinc-50 text-zinc-800 dark:border-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-200',
};

const today = new Date().toLocaleDateString('en-CA');
const form = reactive({ spent_on: today, source: '', campaign: '', amount: '', note: '' });

function setDays(d) {
    router.get('/inteligencia', { days: d }, { preserveScroll: true, preserveState: true });
}
function addSpend() {
    router.post('/inteligencia/custos', form, { preserveScroll: true, onSuccess: () => Object.assign(form, { amount: '', note: '' }) });
}
function removeSpend(s) {
    if (!window.confirm('Remover este custo?')) return;
    router.delete(`/inteligencia/custos/${s.id}`, { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-semibold"><Lightbulb class="h-6 w-6" /> Inteligência</h1>
                <p class="mt-1 max-w-2xl text-sm text-zinc-500">De onde vêm as vendas pagas, quanto custam e onde há dinheiro escapando. Receita conta só pagamento confirmado. ROAS e CAC aparecem apenas onde você informou o custo dos anúncios.</p>
            </div>
            <div class="flex gap-1 rounded-xl border border-zinc-200 p-1 dark:border-zinc-700">
                <button v-for="d in periods" :key="d" type="button" class="rounded-lg px-3 py-1.5 text-sm" :class="d === days ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'text-zinc-600 dark:text-zinc-300'" @click="setDays(d)">{{ d }} dias</button>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
            <div v-for="k in [
                ['Receita confirmada', brl(report.totals.revenue_cents)],
                ['Vendas pagas', report.totals.paid_orders],
                ['Clientes novos', report.totals.new_customers],
                ['ROAS', roas(report.totals.roas)],
                ['CAC', brl(report.totals.cac_cents)],
            ]" :key="k[0]" class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80">
                <p class="text-xs text-zinc-500">{{ k[0] }}</p>
                <p class="mt-1 text-xl font-semibold tabular-nums">{{ k[1] }}</p>
            </div>
        </div>

        <section v-if="report.insights.length" class="space-y-2">
            <h2 class="font-semibold">Alertas</h2>
            <div v-for="(i, idx) in report.insights" :key="idx" class="rounded-xl border px-4 py-3 text-sm" :class="LEVEL[i.level]">
                <p class="font-medium">{{ i.title }}</p>
                <p class="mt-0.5 opacity-90">{{ i.detail }}</p>
                <a v-if="i.href" :href="i.href" class="mt-1 inline-block text-xs font-medium underline">Abrir</a>
            </div>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900/80">
            <h2 class="font-semibold">Campanhas</h2>
            <p class="text-xs text-zinc-500">Origem pela UTM do checkout. Custo vem do que você cadastrou abaixo.</p>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[640px] text-sm">
                    <thead class="text-left text-xs text-zinc-500"><tr><th class="py-1">Origem</th><th>Campanha</th><th>Vendas</th><th>Receita</th><th>Novos</th><th>Custo</th><th>ROAS</th><th>CAC</th></tr></thead>
                    <tbody>
                        <tr v-for="c in report.campaigns" :key="c.source + '|' + (c.campaign || '')" class="border-t border-zinc-100 dark:border-zinc-800">
                            <td class="py-1.5">{{ c.source }}</td><td>{{ c.campaign || '—' }}</td><td>{{ c.paid_orders }}</td><td>{{ brl(c.revenue_cents) }}</td>
                            <td>{{ c.new_customers }}</td><td>{{ brl(c.spend_cents) }}</td><td>{{ roas(c.roas) }}</td><td>{{ brl(c.cac_cents) }}</td>
                        </tr>
                        <tr v-if="!report.campaigns.length"><td colspan="8" class="py-4 text-center text-zinc-500">Sem vendas pagas no período.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900/80">
                <h2 class="font-semibold">Aprovação por método</h2>
                <p class="text-xs text-zinc-500">Pedidos novos (sem renovações automáticas). Aprovados incluem os depois reembolsados.</p>
                <table class="mt-3 w-full text-sm">
                    <thead class="text-left text-xs text-zinc-500"><tr><th class="py-1">Método</th><th>Tentativas</th><th>Aprovação</th><th>Pendentes</th><th>Receita</th></tr></thead>
                    <tbody>
                        <tr v-for="m in report.payments" :key="m.method" class="border-t border-zinc-100 dark:border-zinc-800">
                            <td class="py-1.5">{{ METHOD[m.method] || m.method }}</td><td>{{ m.attempts }}</td><td>{{ pct(m.approval_rate) }}</td><td>{{ m.pending }}</td><td>{{ brl(m.revenue_cents) }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
            <section class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900/80">
                <h2 class="font-semibold">Produtos</h2>
                <p class="text-xs text-zinc-500">Perda = reembolsos e disputas sobre vendas aprovadas.</p>
                <table class="mt-3 w-full text-sm">
                    <thead class="text-left text-xs text-zinc-500"><tr><th class="py-1">Produto</th><th>Pagas</th><th>Receita</th><th>Perda</th></tr></thead>
                    <tbody>
                        <tr v-for="p in report.products" :key="p.product_id" class="border-t border-zinc-100 dark:border-zinc-800">
                            <td class="max-w-[180px] truncate py-1.5">{{ p.name }}</td><td>{{ p.paid }}</td><td>{{ brl(p.revenue_cents) }}</td><td>{{ pct(p.loss_rate) }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>

        <section v-if="can_edit_spend" class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900/80">
            <h2 class="font-semibold">Custo dos anúncios</h2>
            <p class="text-xs text-zinc-500">Use a mesma origem e campanha das UTMs (ex.: origem "facebook", campanha "black-friday"). Sem campanha, o custo conta só no total da origem.</p>
            <div class="mt-3 grid gap-2 md:grid-cols-6">
                <input v-model="form.spent_on" type="date" :max="today" class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800" />
                <input v-model="form.source" maxlength="100" placeholder="Origem (utm_source)" class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800" />
                <input v-model="form.campaign" maxlength="191" placeholder="Campanha (opcional)" class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800" />
                <input v-model="form.amount" inputmode="decimal" placeholder="Valor (R$)" class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800" />
                <input v-model="form.note" maxlength="255" placeholder="Observação" class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800" />
                <Button type="button" :disabled="!form.source || !form.amount" @click="addSpend">Adicionar</Button>
            </div>
            <div class="mt-4 overflow-x-auto">
                <table class="w-full min-w-[520px] text-sm">
                    <thead class="text-left text-xs text-zinc-500"><tr><th class="py-1">Data</th><th>Origem</th><th>Campanha</th><th>Valor</th><th>Obs.</th><th /></tr></thead>
                    <tbody>
                        <tr v-for="s in spends" :key="s.id" class="border-t border-zinc-100 dark:border-zinc-800">
                            <td class="py-1.5">{{ s.spent_on.split('-').reverse().join('/') }}</td><td>{{ s.source }}</td><td>{{ s.campaign || '—' }}</td><td>{{ brl(s.amount_cents) }}</td><td class="max-w-[160px] truncate">{{ s.note }}</td>
                            <td class="text-right"><button type="button" class="text-zinc-400 hover:text-red-600" aria-label="Remover" @click="removeSpend(s)"><Trash2 class="h-4 w-4" /></button></td>
                        </tr>
                        <tr v-if="!spends.length"><td colspan="6" class="py-4 text-center text-zinc-500">Nenhum custo no período.</td></tr>
                    </tbody>
                </table>
            </div>
        </section>
        <p v-else class="text-xs text-zinc-500">ROAS e CAC ficam visíveis só para o dono da conta, porque o custo dos anúncios vale para todos os produtos.</p>
    </div>
</template>
