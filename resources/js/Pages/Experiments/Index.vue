<script setup>
import { computed, reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import Button from '@/components/ui/Button.vue';
import { FlaskConical } from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

/**
 * Brasa Experiments — A/B da landing do checkout. Conversão = venda PAGA por sessão.
 * Vencedora só com amostra mínima e significância estatística.
 */
const props = defineProps({
    experiments: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    versions: { type: Array, default: () => [] },
    min: { type: Object, required: true },
});

const STATUS = { draft: 'Rascunho', running: 'Rodando', paused: 'Pausado', finished: 'Encerrado' };
const pct = (v) => (v === null || v === undefined ? '—' : `${(v * 100).toFixed(2)}%`);
const brl = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(v) || 0);

const form = reactive({ name: '', product_id: '', version_id: '', weight_b: 50 });
const productVersions = computed(() => props.versions.filter((v) => v.product_id === form.product_id));

function create() {
    router.post('/experimentos', form, { preserveScroll: true, onSuccess: () => Object.assign(form, { name: '', version_id: '' }) });
}
function act(e, action) {
    router.post(`/experimentos/${e.id}/acao`, { action }, { preserveScroll: true });
}
function apply(e) {
    if (!window.confirm('Aplicar a vencedora? Se for a B, ela vira a landing publicada do produto.')) return;
    router.post(`/experimentos/${e.id}/aplicar-vencedora`, {}, { preserveScroll: true });
}
function verdictText(v) {
    if (!v) return '';
    if (v.status === 'winner') return `Vencedora: ${v.winner}${v.lift !== null && v.lift !== undefined ? ` (${(v.lift * 100).toFixed(1)}% de diferença)` : ''} · p = ${v.p_value}`;
    return v.reason || 'Inconclusivo';
}
</script>

<template>
    <div class="space-y-6">
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-semibold"><FlaskConical class="h-6 w-6" /> Testes A/B</h1>
            <p class="mt-1 max-w-2xl text-sm text-zinc-500">
                Compare a landing publicada (A) com uma versão salva (B). Cada visitante vê sempre a mesma variante.
                Conta como conversão só a venda paga. Uma variante só vence com pelo menos {{ min.sessions }} sessões em cada
                lado, {{ min.conversions }} vendas pagas no total e diferença estatisticamente significativa.
            </p>
        </div>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900/80">
            <h2 class="font-semibold">Novo experimento</h2>
            <div class="mt-3 grid gap-3 md:grid-cols-4">
                <input v-model="form.name" maxlength="120" placeholder="Nome (ex.: Headline nova)" class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800" />
                <select v-model="form.product_id" class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800" @change="form.version_id = ''">
                    <option value="">Produto…</option>
                    <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>
                <select v-model="form.version_id" class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800" :disabled="!form.product_id">
                    <option value="">Versão B da landing…</option>
                    <option v-for="v in productVersions" :key="v.id" :value="v.id">{{ new Date(v.created_at).toLocaleString('pt-BR') }} · {{ v.blocks }} blocos</option>
                </select>
                <label class="flex items-center gap-2 text-sm">Tráfego na B <input v-model.number="form.weight_b" type="number" min="10" max="90" class="w-20 rounded-xl border border-zinc-200 bg-white px-2 py-2 dark:border-zinc-700 dark:bg-zinc-800" /> %</label>
            </div>
            <p v-if="form.product_id && !productVersions.length" class="mt-2 text-xs text-zinc-500">Este produto ainda não tem versões salvas da landing por blocos. Salve uma versão no editor do checkout.</p>
            <Button type="button" class="mt-3" :disabled="!form.name || !form.product_id || !form.version_id" @click="create">Criar</Button>
        </section>

        <p v-if="!experiments.length" class="rounded-2xl border border-dashed border-zinc-300 py-10 text-center text-sm text-zinc-500 dark:border-zinc-700">Nenhum experimento ainda.</p>

        <section v-for="e in experiments" :key="e.id" class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900/80">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p class="font-semibold">{{ e.name }}</p>
                    <p class="text-xs text-zinc-500">{{ e.product }} · {{ STATUS[e.status] }} · A {{ e.weights.A }}% / B {{ e.weights.B }}%</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <Button v-if="e.status === 'draft' || e.status === 'paused'" size="sm" @click="act(e, 'start')">Iniciar</Button>
                    <Button v-if="e.status === 'running'" size="sm" variant="outline" @click="act(e, 'pause')">Pausar</Button>
                    <Button v-if="e.status !== 'finished'" size="sm" variant="ghost" @click="act(e, 'finish')">Encerrar</Button>
                    <Button v-if="e.results.verdict.status === 'winner' && !e.winner_key" size="sm" @click="apply(e)">Aplicar vencedora</Button>
                </div>
            </div>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[520px] text-sm">
                    <thead class="text-left text-xs text-zinc-500"><tr><th class="py-1">Variante</th><th>Sessões</th><th>Iniciaram</th><th>Pedidos</th><th>Pagos</th><th>Conversão</th><th>Receita</th></tr></thead>
                    <tbody>
                        <tr v-for="(r, key) in e.results.variants" :key="key" class="border-t border-zinc-100 dark:border-zinc-800">
                            <td class="py-1.5">{{ r.label }}</td><td>{{ r.sessions }}</td><td>{{ r.started }}</td><td>{{ r.orders }}</td><td>{{ r.paid }}</td><td>{{ pct(r.conversion) }}</td><td>{{ brl(r.revenue) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs" :class="e.results.verdict.status === 'winner' ? 'text-emerald-700' : 'text-zinc-500'">{{ verdictText(e.results.verdict) }}</p>
            <p v-if="e.winner_key" class="mt-1 text-xs text-zinc-500">Aplicada: variante {{ e.winner_key }}.</p>
        </section>
    </div>
</template>
