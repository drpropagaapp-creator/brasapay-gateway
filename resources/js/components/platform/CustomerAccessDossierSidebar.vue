<script setup>
import { ref, watch } from 'vue';
import { X, Package, ClipboardList } from 'lucide-vue-next';
import axios from 'axios';
import AccessDossierView from '@/components/member/AccessDossierView.vue';

const props = defineProps({
    open: { type: Boolean, default: false },
    customer: { type: Object, default: null },
    products: { type: Array, default: () => [] },
    initialProductId: { type: [String, Number], default: null },
});

const emit = defineEmits(['close']);

const dossier = ref(null);
const dossierLoading = ref(false);
const dossierError = ref('');
const selectedProductId = ref(null);

watch(
    () => [props.open, props.customer?.id, props.initialProductId],
    ([isOpen]) => {
        if (!isOpen) {
            resetDossier();
            return;
        }
        if (props.initialProductId) {
            openDossier({ id: props.initialProductId });
            return;
        }
        resetDossier();
    }
);

function resetDossier() {
    dossier.value = null;
    dossierLoading.value = false;
    dossierError.value = '';
    selectedProductId.value = null;
}

function close() {
    resetDossier();
    emit('close');
}

function closeDossier() {
    resetDossier();
}

async function openDossier(produto) {
    if (!props.customer?.id || !produto?.id) return;
    selectedProductId.value = produto.id;
    dossierLoading.value = true;
    dossierError.value = '';
    dossier.value = null;
    try {
        const { data } = await axios.get(
            `/plataforma/clientes/${props.customer.id}/produtos/${produto.id}/dossie`
        );
        dossier.value = data;
    } catch (err) {
        dossier.value = null;
        dossierError.value = err.response?.data?.message ?? 'Não foi possível carregar o dossiê.';
    } finally {
        dossierLoading.value = false;
    }
}

function dossierExportUrl(format) {
    const productId = dossier.value?.product?.id ?? selectedProductId.value;
    if (!props.customer?.id || !productId) return '#';
    const base = `/plataforma/clientes/${props.customer.id}/produtos/${productId}/dossie/exportar`;
    return format === 'pdf' ? `${base}.pdf` : base;
}

function formatEnrolledAt(iso) {
    if (!iso) return null;
    try {
        return new Date(iso).toLocaleString('pt-BR', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
        });
    } catch (_) {
        return null;
    }
}
</script>

<template>
    <Teleport to="body">
        <div
            v-show="open"
            class="fixed inset-0 z-[100000] flex justify-end"
            aria-modal="true"
            role="dialog"
        >
            <div
                class="fixed inset-0 bg-zinc-900/50 dark:bg-zinc-950/60"
                aria-hidden="true"
                @click="close"
            />
            <aside
                class="relative flex h-full w-full max-w-md flex-col rounded-l-2xl bg-white shadow-2xl dark:bg-zinc-900"
            >
                <div class="flex items-center justify-between rounded-tl-2xl px-5 py-5">
                    <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">
                        {{ dossier || dossierLoading || dossierError ? 'Dossiê de acesso' : 'Acessos do cliente' }}
                    </h2>
                    <button
                        type="button"
                        class="rounded-lg p-2 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-800 dark:hover:text-zinc-300"
                        aria-label="Fechar"
                        @click="close"
                    >
                        <X class="h-5 w-5" />
                    </button>
                </div>

                <div v-if="!customer" class="flex flex-1 items-center justify-center p-8">
                    <p class="text-sm text-zinc-500">Nenhum cliente selecionado.</p>
                </div>

                <div v-else class="flex flex-1 flex-col overflow-hidden">
                    <div class="flex-1 overflow-y-auto p-5">
                        <AccessDossierView
                            v-if="dossier || dossierLoading || dossierError"
                            :dossier="dossier"
                            :loading="dossierLoading"
                            :error="dossierError"
                            :csv-url="dossierExportUrl('csv')"
                            :pdf-url="dossierExportUrl('pdf')"
                            back-label="Voltar aos produtos"
                            @back="closeDossier"
                        />
                        <div v-else class="space-y-5">
                            <div class="space-y-1">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                    Cliente
                                </p>
                                <p class="text-sm text-zinc-900 dark:text-white">{{ customer.name }}</p>
                                <p class="text-sm text-zinc-500">{{ customer.email }}</p>
                            </div>
                            <div class="space-y-2">
                                <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                    Produtos com acesso
                                </p>
                                <button
                                    v-for="p in products"
                                    :key="p.id"
                                    type="button"
                                    class="flex w-full items-center justify-between gap-2 rounded-lg border border-zinc-200 bg-zinc-50 py-2 pl-3 pr-2 text-left dark:border-zinc-700 dark:bg-zinc-800/50"
                                    @click="openDossier(p)"
                                >
                                    <span class="flex min-w-0 flex-1 items-center gap-2 text-sm text-zinc-900 dark:text-white">
                                        <Package class="h-4 w-4 shrink-0 text-zinc-500" />
                                        <span class="min-w-0">
                                            <span class="block truncate">{{ p.name }}</span>
                                            <span v-if="p.seller?.name" class="block truncate text-xs text-zinc-500">
                                                {{ p.seller.name }}
                                                <template v-if="formatEnrolledAt(p.enrolled_at)">
                                                    · desde {{ formatEnrolledAt(p.enrolled_at) }}
                                                </template>
                                            </span>
                                        </span>
                                    </span>
                                    <ClipboardList class="h-3.5 w-3.5 shrink-0 text-zinc-400" />
                                </button>
                                <p v-if="!products.length" class="text-sm text-zinc-500">
                                    Nenhum produto com matrícula neste cliente. Você ainda pode abrir o dossiê pelo pedido, se houver histórico de acesso.
                                </p>
                            </div>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
    </Teleport>
</template>
