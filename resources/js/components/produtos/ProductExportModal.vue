<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import Button from '@/components/ui/Button.vue';

/**
 * Exportar produto para um pacote .brasapay.zip, com escolha das dependências
 * (produtos usados em order bump, upsell, downsell e área de membros).
 */
const props = defineProps({
    product: { type: Object, default: null },
});
const emit = defineEmits(['close']);

const loading = ref(false);
const exporting = ref(false);
const error = ref('');
const dependencies = ref([]);
const mode = ref('main');
const selected = ref([]);

watch(
    () => props.product,
    async (p) => {
        error.value = '';
        dependencies.value = [];
        selected.value = [];
        mode.value = 'main';
        if (!p) return;
        loading.value = true;
        try {
            const { data } = await axios.get(`/produtos/${p.id}/exportar/opcoes`);
            dependencies.value = data.dependencies || [];
        } catch (e) {
            error.value = 'Não foi possível carregar as opções de exportação.';
        } finally {
            loading.value = false;
        }
    },
    { immediate: true }
);

const chosen = computed(() => {
    if (mode.value === 'full') return dependencies.value.map((d) => d.id);
    if (mode.value === 'selected') return selected.value;
    return [];
});

async function exportNow() {
    exporting.value = true;
    error.value = '';
    try {
        const response = await axios.post(
            `/produtos/${props.product.id}/exportar`,
            { dependencies: chosen.value },
            { responseType: 'blob' }
        );
        const disposition = response.headers['content-disposition'] || '';
        const match = /filename="?([^";]+)"?/i.exec(disposition);
        const url = URL.createObjectURL(response.data);
        const a = document.createElement('a');
        a.href = url;
        a.download = match ? match[1] : 'produto.brasapay.zip';
        document.body.appendChild(a);
        a.click();
        a.remove();
        URL.revokeObjectURL(url);
        emit('close');
    } catch (e) {
        error.value = 'Não foi possível gerar o pacote. Tente de novo.';
    } finally {
        exporting.value = false;
    }
}
</script>

<template>
    <div v-if="product" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" role="dialog" aria-modal="true" aria-labelledby="export-title" @click.self="emit('close')">
        <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl dark:bg-zinc-900">
            <h2 id="export-title" class="text-lg font-semibold text-zinc-900 dark:text-white">Exportar "{{ product.name }}"</h2>
            <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                Gera um arquivo .brasapay.zip com o produto, ofertas, planos, checkout, landing page, upsell/downsell,
                área de membros e mídias. Credenciais, adquirentes, cupons, integrações e dados de compradores nunca entram.
            </p>

            <p v-if="loading" class="mt-4 text-sm text-zinc-500">Carregando…</p>
            <div v-else class="mt-4 space-y-2">
                <label class="flex items-start gap-2 text-sm">
                    <input v-model="mode" type="radio" value="main" class="mt-1" />
                    <span><b>Só este produto.</b> Referências a outros produtos ficam marcadas no pacote.</span>
                </label>
                <template v-if="dependencies.length">
                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="mode" type="radio" value="selected" class="mt-1" />
                        <span><b>Este produto e as dependências que eu escolher.</b></span>
                    </label>
                    <div v-if="mode === 'selected'" class="ml-6 space-y-1">
                        <label v-for="d in dependencies" :key="d.id" class="flex items-center gap-2 text-sm">
                            <input v-model="selected" type="checkbox" :value="d.id" />
                            {{ d.name }} <span class="text-xs text-zinc-500">({{ d.reasons.join(', ') }})</span>
                        </label>
                    </div>
                    <label class="flex items-start gap-2 text-sm">
                        <input v-model="mode" type="radio" value="full" class="mt-1" />
                        <span><b>Conjunto completo</b> — inclui os {{ dependencies.length }} produtos relacionados.</span>
                    </label>
                </template>
                <p v-else class="text-xs text-zinc-500">Este produto não usa outros produtos (bump, upsell ou área de membros).</p>
            </div>

            <p v-if="error" class="mt-3 text-sm text-red-600">{{ error }}</p>

            <div class="mt-6 flex justify-end gap-2">
                <Button type="button" variant="secondary" :disabled="exporting" @click="emit('close')">Cancelar</Button>
                <Button type="button" :disabled="loading || exporting" @click="exportNow">
                    {{ exporting ? 'Gerando pacote…' : 'Exportar' }}
                </Button>
            </div>
        </div>
    </div>
</template>
