<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import Button from '@/components/ui/Button.vue';
import ProdutosTabs from '@/components/produtos/ProdutosTabs.vue';
import AuroraPageHeader from '@/components/aurora/AuroraPageHeader.vue';
import AuroraPageSection from '@/components/aurora/AuroraPageSection.vue';
import { usePanelThemeClasses } from '@/composables/usePanelThemeClasses';
import { AlertTriangle, CheckCircle2, Loader2, Upload, XCircle } from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });
const { pageClass } = usePanelThemeClasses();

const props = defineProps({
    import: { type: Object, default: null },
    max_mb: { type: Number, default: 200 },
});

const current = ref(props.import);
const form = useForm({ package: null });
const TYPE_LABELS = {
    aplicativo: 'Aplicativo',
    area_membros: 'Área de membros',
    area_membros_externa: 'Área de membros externa',
    link: 'Link',
    link_pagamento: 'Link de pagamento',
    produto_fisico: 'Produto físico',
};
const METHOD_LABELS = { pix: 'PIX', card: 'Cartão', boleto: 'Boleto', pix_auto: 'PIX automático', open_finance: 'Open Finance', paypal: 'PayPal', apple_pay: 'Apple Pay', google_pay: 'Google Pay' };

const status = computed(() => current.value?.status || null);
const summary = computed(() => current.value?.summary || null);
const result = computed(() => current.value?.result || null);
const blockedMethods = computed(() =>
    [...new Set((summary.value?.products || []).flatMap((p) => p.payment_methods_blocked_here || []))]
);

let poll = null;
function startPolling() {
    clearInterval(poll);
    poll = setInterval(async () => {
        try {
            const { data } = await axios.get(`/produtos/importar/${current.value.uuid}/status`);
            current.value = data;
            if (!['importing', 'validating'].includes(data.status)) clearInterval(poll);
        } catch (_) {}
    }, 2000);
}

onMounted(() => {
    if (['importing', 'validating'].includes(status.value)) startPolling();
});
onBeforeUnmount(() => clearInterval(poll));

function pickFile(event) {
    form.package = event.target.files?.[0] || null;
}

function upload() {
    if (!form.package) return;
    form.post('/produtos/importar', { forceFormData: true });
}

function confirmImport() {
    router.post(`/produtos/importar/${current.value.uuid}/confirmar`, {}, {
        preserveScroll: true,
        onSuccess: () => {
            current.value = { ...current.value, status: 'importing' };
            startPolling();
        },
    });
}

function cancelImport() {
    router.post(`/produtos/importar/${current.value.uuid}/cancelar`);
}

const brl = (v, c = 'BRL') => Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: c || 'BRL' });
</script>

<template>
    <div :class="pageClass">
        <AuroraPageHeader title="Importar produto" subtitle="Recrie na sua conta um produto exportado de outra conta BrasaPay." />
        <ProdutosTabs />

        <AuroraPageSection>
            <!-- 1. Envio -->
            <div v-if="!current || status === 'failed' || status === 'cancelled'" class="mx-auto max-w-2xl space-y-4">
                <div v-if="status === 'failed'" class="flex gap-2 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300">
                    <XCircle class="h-5 w-5 shrink-0" />
                    <div>
                        <p class="font-medium">Não foi possível importar este pacote.</p>
                        <p class="mt-0.5">{{ current.error }}</p>
                    </div>
                </div>

                <label class="flex cursor-pointer flex-col items-center gap-3 rounded-2xl border-2 border-dashed border-zinc-300 p-10 text-center hover:border-[var(--color-primary)] dark:border-zinc-600">
                    <Upload class="h-8 w-8 text-zinc-400" />
                    <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">
                        {{ form.package ? form.package.name : 'Escolha o arquivo .brasapay.zip' }}
                    </span>
                    <span class="text-xs text-zinc-500">Até {{ max_mb }} MB. Gerado em "Exportar produto" de uma conta BrasaPay.</span>
                    <input type="file" accept=".zip,application/zip" class="sr-only" @change="pickFile" />
                </label>
                <p v-if="form.errors.package" class="text-sm text-red-600">{{ form.errors.package }}</p>
                <div class="flex justify-end gap-2">
                    <Link href="/produtos" class="rounded-lg px-3 py-2 text-sm text-zinc-600 hover:underline">Voltar</Link>
                    <Button type="button" :disabled="!form.package || form.processing" @click="upload">
                        {{ form.processing ? 'Validando…' : 'Enviar e ver prévia' }}
                    </Button>
                </div>
            </div>

            <!-- 2. Prévia -->
            <div v-else-if="status === 'ready'" class="mx-auto max-w-3xl space-y-4">
                <p class="text-sm text-zinc-600 dark:text-zinc-400">
                    Confira o que será criado na sua conta. Os produtos entram <b>inativos</b> e passam pela aprovação da sua conta;
                    adquirentes, cupons, integrações e coprodutores não são copiados.
                </p>

                <div v-for="p in summary.products" :key="p.key" class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <div class="flex flex-wrap items-baseline justify-between gap-2">
                        <h3 class="font-semibold text-zinc-900 dark:text-white">{{ p.name }}</h3>
                        <span class="text-sm text-zinc-600 dark:text-zinc-300">{{ TYPE_LABELS[p.type] || p.type }} · {{ brl(p.price, p.currency) }}</span>
                    </div>
                    <ul class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-zinc-600 dark:text-zinc-400">
                        <li>{{ p.offers }} ofertas</li>
                        <li>{{ p.plans }} planos</li>
                        <li>{{ p.order_bumps }} order bumps</li>
                        <li>Landing: {{ p.landing_mode === 'blocks' ? 'editor por blocos' : 'seções simples' }}</li>
                        <li v-if="p.member_sections">{{ p.member_sections }} seções e {{ p.member_lessons }} aulas na área de membros</li>
                    </ul>
                    <p v-if="p.coproduction_template" class="mt-2 text-xs text-amber-700 dark:text-amber-400">
                        Tinha {{ p.coproduction_template }} coprodutor(es): convide de novo depois — a divisão só vale após o aceite.
                    </p>
                </div>

                <div v-if="blockedMethods.length" class="flex gap-2 rounded-xl bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-950/30 dark:text-amber-300">
                    <AlertTriangle class="h-5 w-5 shrink-0" />
                    <span>Estes métodos de pagamento estão desativados na sua conta e não vão aparecer no checkout:
                        <b>{{ blockedMethods.map((m) => METHOD_LABELS[m] || m).join(', ') }}</b>.</span>
                </div>
                <div v-if="summary.missing_references?.length" class="flex gap-2 rounded-xl bg-zinc-100 p-3 text-sm text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                    <AlertTriangle class="h-5 w-5 shrink-0" />
                    <span>{{ summary.missing_references.length }} referência(s) a produtos que não vieram no pacote (order bump, upsell, downsell ou área de membros) serão removidas.</span>
                </div>
                <p class="text-xs text-zinc-500">{{ summary.assets }} arquivos de mídia serão copiados para o seu armazenamento.</p>

                <div class="flex justify-end gap-2">
                    <Button type="button" variant="secondary" @click="cancelImport">Cancelar</Button>
                    <Button type="button" @click="confirmImport">Importar {{ summary.products.length > 1 ? `${summary.products.length} produtos` : 'produto' }}</Button>
                </div>
            </div>

            <!-- 3. Progresso -->
            <div v-else-if="status === 'importing' || status === 'validating'" class="mx-auto flex max-w-md flex-col items-center gap-3 py-12 text-center">
                <Loader2 class="h-8 w-8 animate-spin text-[var(--color-primary)]" />
                <p class="font-medium">Importando…</p>
                <p class="text-sm text-zinc-500">Estamos recriando o produto e copiando as mídias. Pode sair desta página: a importação continua.</p>
            </div>

            <!-- 4. Resultado -->
            <div v-else-if="status === 'completed'" class="mx-auto max-w-2xl space-y-4">
                <div class="flex gap-2 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-300">
                    <CheckCircle2 class="h-5 w-5 shrink-0" />
                    <span>Importação concluída. Revise cada produto, configure o financeiro e ative quando estiver pronto.</span>
                </div>
                <ul class="divide-y divide-zinc-100 rounded-xl border border-zinc-200 dark:divide-zinc-800 dark:border-zinc-700">
                    <li v-for="p in result.products" :key="p.id" class="flex items-center justify-between px-4 py-3 text-sm">
                        <span class="font-medium">{{ p.name }}</span>
                        <Link :href="`/produtos/${p.id}/edit`" class="text-[var(--color-primary)] hover:underline">Editar produto</Link>
                    </li>
                </ul>
                <div v-if="result.warnings?.length" class="space-y-1 rounded-xl bg-amber-50 p-3 text-sm text-amber-800 dark:bg-amber-950/30 dark:text-amber-300">
                    <p class="font-medium">Pontos para revisar:</p>
                    <p v-for="(w, i) in result.warnings" :key="i">• {{ w }}</p>
                </div>
            </div>
        </AuroraPageSection>
    </div>
</template>
