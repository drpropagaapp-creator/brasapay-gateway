<script setup>
import { Link, router } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';
import { Gauge, RefreshCw } from 'lucide-vue-next';

defineOptions({ layout: LayoutPlatform });

/** Brasa Command Center v1 — só leitura; blocos sem fonte aparecem como indisponíveis. */
const props = defineProps({
    executive: { type: Object, default: null },
    sellers: { type: Object, default: null },
    withdrawals: { type: Object, default: null },
    treasury: { type: Object, default: null },
    risk: { type: Object, default: null },
    acquirers: { type: Array, default: null },
    infra: { type: Object, default: null },
    alerts: { type: Array, default: () => [] },
    generated_at: { type: String, default: null },
});

const brl = (v) => new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(v) || 0);
const pct = (v) => (v === null || v === undefined ? '—' : `${(v * 100).toFixed(1)}%`);
const SEV = {
    critical: 'border-red-300 bg-red-50 text-red-900 dark:border-red-900 dark:bg-red-950/30 dark:text-red-200',
    high: 'border-orange-300 bg-orange-50 text-orange-900 dark:border-orange-900 dark:bg-orange-950/30 dark:text-orange-200',
    attention: 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-900 dark:bg-amber-950/30 dark:text-amber-200',
    info: 'border-zinc-200 bg-zinc-50 text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
};
const SEV_LABEL = { critical: 'Crítico', high: 'Alto', attention: 'Atenção', info: 'Informativo' };

function refresh() {
    router.reload({ preserveScroll: true });
}
</script>

<template>
    <div class="mx-auto max-w-6xl space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Operação</p>
                <h1 class="mt-1 flex items-center gap-2 text-2xl font-bold text-zinc-900 dark:text-white"><Gauge class="h-6 w-6" /> Command Center</h1>
                <p class="mt-1 text-xs text-zinc-500">Atualizado {{ generated_at ? new Date(generated_at).toLocaleTimeString('pt-BR') : '—' }} (cache de 1 min)</p>
            </div>
            <Button type="button" variant="outline" class="gap-2" @click="refresh"><RefreshCw class="h-4 w-4" /> Atualizar</Button>
        </div>

        <section>
            <h2 class="mb-2 text-sm font-semibold text-zinc-700 dark:text-zinc-300">Alertas</h2>
            <p v-if="!alerts.length" class="rounded-2xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/30 dark:text-emerald-200">Nenhum alerta agora.</p>
            <ul v-else class="space-y-2">
                <li v-for="(a, i) in alerts" :key="i" class="flex flex-wrap items-center justify-between gap-2 rounded-2xl border p-3 text-sm" :class="SEV[a.severity]">
                    <span><b>{{ SEV_LABEL[a.severity] }}</b> — {{ a.text }}</span>
                    <Link v-if="a.href" :href="a.href" class="text-xs font-medium underline">Abrir</Link>
                </li>
            </ul>
        </section>

        <section class="grid grid-cols-2 gap-3 lg:grid-cols-4">
            <template v-if="executive">
                <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80"><p class="text-xs text-zinc-500">Aprovado hoje</p><p class="mt-1 text-xl font-semibold">{{ brl(executive.volume_today) }}</p><p class="text-xs text-zinc-500">{{ executive.orders_today }} pedidos</p></div>
                <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80"><p class="text-xs text-zinc-500">Aprovado 7 dias</p><p class="mt-1 text-xl font-semibold">{{ brl(executive.volume_7d) }}</p><p class="text-xs text-zinc-500">{{ executive.orders_7d }} pedidos · {{ executive.pending_24h }} pendentes (24 h)</p></div>
                <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80"><p class="text-xs text-zinc-500">Taxas / líquido hoje</p><p class="mt-1 text-xl font-semibold">{{ brl(executive.fees_today) }}</p><p class="text-xs text-zinc-500">líquido {{ brl(executive.net_today) }}</p></div>
                <div class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80"><p class="text-xs text-zinc-500">Taxas / líquido 7 dias</p><p class="mt-1 text-xl font-semibold">{{ brl(executive.fees_7d) }}</p><p class="text-xs text-zinc-500">líquido {{ brl(executive.net_7d) }} · {{ executive.refunds_7d }} reembolsos</p></div>
            </template>
            <p v-else class="col-span-full text-sm text-zinc-500">Indicadores executivos indisponíveis.</p>
        </section>

        <div class="grid gap-3 lg:grid-cols-3">
            <section class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80">
                <h3 class="font-semibold">Sellers</h3>
                <dl v-if="sellers" class="mt-2 grid grid-cols-2 gap-2 text-sm">
                    <dt class="text-zinc-500">Ativos</dt><dd class="text-right">{{ sellers.active }}</dd>
                    <dt class="text-zinc-500">Suspensos/bloqueados</dt><dd class="text-right">{{ sellers.suspended }}</dd>
                    <dt class="text-zinc-500">KYC em análise</dt><dd class="text-right"><Link href="/plataforma/verificacoes-kyc" class="hover:underline">{{ sellers.kyc_review }}</Link></dd>
                    <dt class="text-zinc-500">Novos (7 d)</dt><dd class="text-right">{{ sellers.new_7d }}</dd>
                </dl>
                <p v-else class="mt-2 text-sm text-zinc-500">Indisponível.</p>
            </section>
            <section class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80">
                <h3 class="font-semibold">Saques</h3>
                <dl v-if="withdrawals" class="mt-2 grid grid-cols-2 gap-2 text-sm">
                    <dt class="text-zinc-500">Aguardando</dt><dd class="text-right"><Link href="/plataforma/saques" class="hover:underline">{{ withdrawals.awaiting }}</Link></dd>
                    <dt class="text-zinc-500">Pagos (7 d)</dt><dd class="text-right">{{ withdrawals.paid_7d }} · {{ brl(withdrawals.paid_amount_7d) }}</dd>
                    <dt class="text-zinc-500">Em investigação</dt><dd class="text-right" :class="withdrawals.investigating ? 'text-orange-600' : ''">{{ withdrawals.investigating }}</dd>
                    <dt class="text-zinc-500">Pedidos em MED</dt><dd class="text-right">{{ executive?.disputed_open ?? '—' }}</dd>
                </dl>
                <p v-else class="mt-2 text-sm text-zinc-500">Indisponível.</p>
            </section>
            <section class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80">
                <h3 class="font-semibold">Tesouraria e risco</h3>
                <dl class="mt-2 grid grid-cols-2 gap-2 text-sm">
                    <dt class="text-zinc-500">Divergências críticas</dt><dd class="text-right" :class="treasury?.critical ? 'text-red-600' : ''"><Link href="/plataforma/tesouraria" class="hover:underline">{{ treasury?.critical ?? '—' }}</Link></dd>
                    <dt class="text-zinc-500">Divergências abertas</dt><dd class="text-right">{{ treasury?.total ?? '—' }}</dd>
                    <dt class="text-zinc-500">Risco alto aberto</dt><dd class="text-right"><Link href="/plataforma/risco" class="hover:underline">{{ risk?.high_open ?? '—' }}</Link></dd>
                    <dt class="text-zinc-500">Risco médio aberto</dt><dd class="text-right">{{ risk?.medium_open ?? '—' }}</dd>
                </dl>
            </section>
        </div>

        <section class="overflow-x-auto rounded-2xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900/80">
            <h3 class="border-b border-zinc-100 px-4 py-3 font-semibold dark:border-zinc-800">Adquirentes — última hora</h3>
            <p v-if="!acquirers || !acquirers.length" class="px-4 py-4 text-sm text-zinc-500">Sem tentativas na última hora.</p>
            <table v-else class="w-full min-w-[560px] text-sm">
                <thead class="text-left text-xs text-zinc-500"><tr><th class="px-4 py-2">Adquirente</th><th class="px-4 py-2">Método</th><th class="px-4 py-2">Tentativas</th><th class="px-4 py-2">Aprovação técnica</th><th class="px-4 py-2">Erro</th><th class="px-4 py-2">Timeout</th><th class="px-4 py-2">p95</th></tr></thead>
                <tbody>
                    <tr v-for="a in acquirers" :key="`${a.slug}-${a.method}`" class="border-t border-zinc-100 dark:border-zinc-800">
                        <td class="px-4 py-2">{{ a.slug }}</td><td class="px-4 py-2">{{ a.method }}</td><td class="px-4 py-2">{{ a.attempts }}</td>
                        <td class="px-4 py-2">{{ pct(a.success_rate) }}</td><td class="px-4 py-2">{{ pct(a.technical_error_rate) }}</td><td class="px-4 py-2">{{ pct(a.timeout_rate) }}</td><td class="px-4 py-2">{{ a.latency_p95_ms ?? '—' }} ms</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/80">
            <h3 class="font-semibold">Infraestrutura</h3>
            <dl v-if="infra" class="mt-2 grid grid-cols-2 gap-2 text-sm md:grid-cols-4">
                <dt class="text-zinc-500">Agendador</dt><dd :class="infra.scheduler_minutes_ago === null || infra.scheduler_minutes_ago > 5 ? 'text-red-600' : ''">{{ infra.scheduler_minutes_ago === null ? 'sem sinal' : `há ${infra.scheduler_minutes_ago} min` }}</dd>
                <dt class="text-zinc-500">Filas (heartbeat)</dt><dd>{{ infra.queue_minutes_ago === null ? 'sem sinal' : `há ${infra.queue_minutes_ago} min` }}</dd>
                <dt class="text-zinc-500">Jobs falhos (24 h)</dt><dd>{{ infra.failed_jobs_24h ?? '—' }}</dd>
                <dt class="text-zinc-500">Servidor web</dt><dd>{{ infra.web_server }}</dd>
            </dl>
            <div v-if="infra?.queues" class="mt-3 flex flex-wrap gap-2 text-xs">
                <span v-for="(size, q) in infra.queues" :key="q" class="rounded-full border border-zinc-200 px-2.5 py-1 dark:border-zinc-700">{{ q }}: {{ size ?? '?' }}</span>
            </div>
        </section>
    </div>
</template>
