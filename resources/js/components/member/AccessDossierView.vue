<script setup>
import { ArrowLeft, Download, FileText, Loader2 } from 'lucide-vue-next';
import Button from '@/components/ui/Button.vue';

defineProps({
    dossier: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    error: { type: String, default: '' },
    csvUrl: { type: String, default: '#' },
    pdfUrl: { type: String, default: '#' },
    backLabel: { type: String, default: 'Voltar aos detalhes' },
});

defineEmits(['back']);

function formatDossierDate(iso) {
    if (!iso) return '—';
    try {
        return new Date(iso).toLocaleString('pt-BR', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            second: '2-digit',
        });
    } catch (_) {
        return iso;
    }
}

function formatMoney(amount) {
    if (amount === null || amount === undefined) return '—';
    return Number(amount).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}
</script>

<template>
    <div class="space-y-5">
        <button
            type="button"
            class="inline-flex items-center gap-1 text-xs font-medium text-zinc-500 hover:text-zinc-800 dark:hover:text-zinc-200"
            @click="$emit('back')"
        >
            <ArrowLeft class="h-3.5 w-3.5" />
            {{ backLabel }}
        </button>
        <div v-if="loading" class="flex items-center gap-2 text-sm text-zinc-500">
            <Loader2 class="h-4 w-4 animate-spin" />
            Carregando dossiê...
        </div>
        <p v-else-if="error && !dossier" class="text-sm text-red-600 dark:text-red-400">{{ error }}</p>
        <template v-else-if="dossier">
            <div class="space-y-1">
                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Produto</p>
                <p class="text-sm font-medium text-zinc-900 dark:text-white">{{ dossier.product?.name }}</p>
            </div>
            <div class="grid grid-cols-1 gap-3 rounded-lg border border-zinc-200 bg-zinc-50 p-3 text-xs dark:border-zinc-700 dark:bg-zinc-800/50">
                <div>
                    <p class="font-medium uppercase tracking-wide text-zinc-500">Acesso concedido</p>
                    <p class="mt-0.5 text-zinc-900 dark:text-white">{{ formatDossierDate(dossier.enrolled_at) }}</p>
                </div>
                <div v-if="dossier.order">
                    <p class="font-medium uppercase tracking-wide text-zinc-500">Compra</p>
                    <p class="mt-0.5 text-zinc-900 dark:text-white">
                        {{ formatMoney(dossier.order.amount) }}
                        · {{ formatDossierDate(dossier.order.paid_at) }}
                    </p>
                </div>
                <div v-if="dossier.progress?.percent !== null && dossier.progress?.percent !== undefined">
                    <p class="font-medium uppercase tracking-wide text-zinc-500">Progresso</p>
                    <p class="mt-0.5 text-zinc-900 dark:text-white">
                        {{ dossier.progress.completed }} / {{ dossier.progress.total }} aulas
                        ({{ dossier.progress.percent }}%)
                    </p>
                </div>
            </div>
            <div class="flex gap-2">
                <Button
                    as="a"
                    variant="outline"
                    class="flex-1 justify-center"
                    :href="csvUrl"
                    download
                >
                    <Download class="h-4 w-4" />
                    CSV
                </Button>
                <Button
                    as="a"
                    variant="outline"
                    class="flex-1 justify-center"
                    :href="pdfUrl"
                    download
                >
                    <FileText class="h-4 w-4" />
                    PDF
                </Button>
            </div>
            <div class="space-y-2">
                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Linha do tempo</p>
                <p
                    v-if="!dossier.events?.length"
                    class="text-sm text-zinc-500"
                >
                    Nenhum acesso, aula ou download registrado ainda. Eventos passam a aparecer depois que o aluno entra na área de membros.
                </p>
                <ol v-else class="space-y-2">
                    <li
                        v-for="(ev, i) in dossier.events"
                        :key="`${ev.event}-${ev.occurred_at}-${i}`"
                        class="rounded-lg border border-zinc-200 px-3 py-2 dark:border-zinc-700"
                    >
                        <p class="text-sm text-zinc-900 dark:text-white">{{ ev.label }}</p>
                        <p class="mt-0.5 text-xs text-zinc-500">
                            {{ formatDossierDate(ev.occurred_at) }}
                            <span v-if="ev.ip"> · IP {{ ev.ip }}</span>
                            <span v-else> · IP —</span>
                        </p>
                    </li>
                </ol>
            </div>
        </template>
    </div>
</template>
