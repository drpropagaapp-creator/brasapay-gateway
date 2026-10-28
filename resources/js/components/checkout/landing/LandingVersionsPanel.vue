<script setup>
import { ref } from 'vue';
import axios from 'axios';

/**
 * Versões publicadas da landing. Restaurar carrega a versão no editor; ela só
 * vai ao ar quando o seller salvar.
 */
const props = defineProps({
    productId: { type: [String, Number], required: true },
    scope: { type: Object, default: () => ({}) },
});
const emit = defineEmits(['restore']);

const open = ref(false);
const loading = ref(false);
const error = ref('');
const versions = ref([]);
const restoringId = ref(null);

function scopeParams() {
    if (props.scope?.type === 'offer' && props.scope.offer_id != null) return { offer_id: props.scope.offer_id };
    if (props.scope?.type === 'plan' && props.scope.plan_id != null) return { plan_id: props.scope.plan_id };
    return {};
}

async function toggle() {
    open.value = !open.value;
    if (!open.value) return;
    loading.value = true;
    error.value = '';
    try {
        const { data } = await axios.get(`/produtos/${props.productId}/landing/versoes`, { params: scopeParams() });
        versions.value = data.versions || [];
    } catch (e) {
        error.value = 'Não foi possível carregar o histórico.';
    } finally {
        loading.value = false;
    }
}

async function restore(version) {
    restoringId.value = version.id;
    try {
        const { data } = await axios.get(`/produtos/${props.productId}/landing/versoes/${version.id}`);
        emit('restore', data.landing);
        open.value = false;
    } catch (e) {
        error.value = 'Não foi possível restaurar esta versão.';
    } finally {
        restoringId.value = null;
    }
}
</script>

<template>
    <div class="rounded-xl border border-zinc-200 dark:border-zinc-700">
        <button type="button" class="flex w-full items-center justify-between px-3 py-2 text-left text-sm font-medium" :aria-expanded="open" @click="toggle">
            Histórico de versões publicadas
            <span class="text-xs text-zinc-500">{{ open ? 'fechar' : 'abrir' }}</span>
        </button>
        <div v-if="open" class="border-t border-zinc-200 px-3 py-2 dark:border-zinc-700">
            <p v-if="loading" class="text-xs text-zinc-500">Carregando…</p>
            <p v-else-if="error" class="text-xs text-red-600">{{ error }}</p>
            <p v-else-if="!versions.length" class="text-xs text-zinc-500">Ainda não há versões. Cada vez que você salva, uma versão fica guardada aqui.</p>
            <ul v-else class="space-y-1">
                <li v-for="(v, i) in versions" :key="v.id" class="flex items-center justify-between gap-2 text-xs">
                    <span>
                        {{ new Date(v.created_at).toLocaleString('pt-BR') }}
                        <span class="text-zinc-500">· {{ v.mode === 'blocks' ? `${v.blocks_count} blocos` : 'seções simples' }}</span>
                        <span v-if="i === 0" class="ml-1 rounded bg-emerald-100 px-1 text-[10px] text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">no ar</span>
                    </span>
                    <button v-if="i > 0" type="button" class="text-[var(--color-primary)] hover:underline disabled:opacity-50" :disabled="restoringId === v.id" @click="restore(v)">
                        {{ restoringId === v.id ? 'Carregando…' : 'Restaurar no editor' }}
                    </button>
                </li>
            </ul>
            <p class="mt-2 text-[11px] text-zinc-500">A versão restaurada só vai ao ar quando você salvar.</p>
        </div>
    </div>
</template>
