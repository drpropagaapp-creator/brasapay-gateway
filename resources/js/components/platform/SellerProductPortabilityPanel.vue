<script setup>
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import Button from '@/components/ui/Button.vue';
import PlatformStepUpModal from '@/components/platform/PlatformStepUpModal.vue';
import { Download, Upload } from 'lucide-vue-next';

/**
 * Exportar/importar produtos em nome do seller (só admin). Toda ação exige motivo
 * e fica no log de auditoria da plataforma.
 */
const props = defineProps({
    merchantId: { type: [Number, String], required: true },
});

const page = usePage();
const base = computed(() => `/plataforma/usuarios/${props.merchantId}/portabilidade`);
const totpEnabled = computed(() => Boolean(page.props.auth?.user?.totp_enabled));

const STATUS_LABELS = {
    validating: 'Validando',
    ready: 'Aguardando confirmação',
    importing: 'Importando',
    completed: 'Concluída',
    failed: 'Falhou',
    cancelled: 'Cancelada',
};

const loading = ref(true);
const loadError = ref('');
const products = ref([]);
const imports = ref([]);
const maxMb = ref(0);
const productId = ref('');
const reason = ref('');
const busy = ref(false);
const actionError = ref('');
const current = ref(null);
const stepUp = ref(null); // 'export' | 'import'
let poll = null;

async function load() {
    loading.value = true;
    loadError.value = '';
    try {
        const { data } = await axios.get(base.value);
        products.value = data.products || [];
        imports.value = data.imports || [];
        maxMb.value = data.max_mb || 0;
    } catch (e) {
        loadError.value = e?.response?.data?.message || 'Não foi possível carregar os produtos deste seller.';
    } finally {
        loading.value = false;
    }
}

const reasonOk = computed(() => reason.value.trim().length >= 5);

function errorMessage(e, fallback) {
    const d = e?.response?.data;
    return d?.message || Object.values(d?.errors || {})?.[0]?.[0] || fallback;
}

function request(kind) {
    actionError.value = '';
    if (!reasonOk.value) {
        actionError.value = 'Informe o motivo (mínimo 5 caracteres) — ele fica registrado na auditoria.';
        return;
    }
    if (totpEnabled.value) {
        stepUp.value = kind;
        return;
    }
    kind === 'export' ? doExport({}) : doConfirm({});
}

async function doExport({ totp_code: totpCode } = {}) {
    if (!productId.value) return;
    busy.value = true;
    try {
        const res = await axios.post(
            `${base.value}/produtos/${productId.value}/exportar`,
            { reason: reason.value.trim(), totp_code: totpCode || undefined },
            { responseType: 'blob', headers: { Accept: 'application/json' } }
        );
        const name = /filename="?([^";]+)"?/.exec(res.headers['content-disposition'] || '')?.[1] || 'produto.brasapay.zip';
        const url = URL.createObjectURL(res.data);
        const a = document.createElement('a');
        a.href = url;
        a.download = name;
        a.click();
        URL.revokeObjectURL(url);
        stepUp.value = null;
    } catch (e) {
        let message = 'Não foi possível exportar.';
        try {
            const text = await e?.response?.data?.text?.();
            const json = text ? JSON.parse(text) : null;
            message = json?.message || Object.values(json?.errors || {})?.[0]?.[0] || message;
        } catch (_) {}
        actionError.value = message;
    } finally {
        busy.value = false;
    }
}

async function onFile(e) {
    const file = e.target?.files?.[0];
    e.target.value = '';
    if (!file) return;
    actionError.value = '';
    busy.value = true;
    try {
        const form = new FormData();
        form.append('package', file);
        const { data } = await axios.post(`${base.value}/importacoes`, form);
        current.value = data;
    } catch (err) {
        actionError.value = errorMessage(err, 'Falha no envio do pacote.');
    } finally {
        busy.value = false;
    }
}

async function doConfirm({ totp_code: totpCode } = {}) {
    if (!current.value) return;
    busy.value = true;
    try {
        const { data } = await axios.post(`${base.value}/importacoes/${current.value.uuid}/confirmar`, {
            reason: reason.value.trim(),
            totp_code: totpCode || undefined,
        });
        current.value = data;
        stepUp.value = null;
        startPolling();
    } catch (e) {
        actionError.value = errorMessage(e, 'Não foi possível confirmar a importação.');
    } finally {
        busy.value = false;
    }
}

async function cancelImport() {
    if (!current.value) return;
    const { data } = await axios.post(`${base.value}/importacoes/${current.value.uuid}/cancelar`);
    current.value = data;
    load();
}

function startPolling() {
    clearInterval(poll);
    poll = setInterval(async () => {
        try {
            const { data } = await axios.get(`${base.value}/importacoes/${current.value.uuid}`);
            current.value = data;
            if (!['importing', 'validating'].includes(data.status)) {
                clearInterval(poll);
                load();
            }
        } catch (_) {
            clearInterval(poll);
        }
    }, 2500);
}

onMounted(load);
onBeforeUnmount(() => clearInterval(poll));
</script>

<template>
    <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <h2 class="font-semibold">Exportar e importar produtos</h2>
        <p class="mt-1 max-w-2xl text-xs text-zinc-500">
            Gera ou aplica um pacote .brasapay.zip em nome deste seller. Adquirentes, cupons, integrações, chaves e dados de
            compradores nunca entram no pacote. Cada ação pede um motivo e fica na auditoria da plataforma.
        </p>

        <p v-if="loading" class="mt-4 text-sm text-zinc-500">Carregando…</p>
        <p v-else-if="loadError" class="mt-4 text-sm text-red-600">{{ loadError }}</p>

        <div v-else class="mt-4 space-y-5">
            <label class="block text-sm">
                <span class="font-medium">Motivo</span>
                <input
                    v-model="reason"
                    type="text"
                    maxlength="500"
                    placeholder="Ex.: migração solicitada pelo seller no ticket #123"
                    class="mt-1 w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                />
            </label>

            <div class="grid gap-4 lg:grid-cols-2">
                <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <h3 class="text-sm font-semibold">Exportar</h3>
                    <select
                        v-model="productId"
                        class="mt-2 w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800"
                    >
                        <option value="">Escolha um produto…</option>
                        <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}{{ p.is_active ? '' : ' (inativo)' }}</option>
                    </select>
                    <Button type="button" class="mt-3" :disabled="busy || !productId" @click="request('export')">
                        <Download class="h-4 w-4" /> Baixar pacote
                    </Button>
                </div>

                <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700">
                    <h3 class="text-sm font-semibold">Importar para esta conta</h3>
                    <p class="mt-1 text-xs text-zinc-500">Até {{ maxMb }} MB. Os produtos entram inativos.</p>
                    <label
                        class="mt-3 inline-flex cursor-pointer items-center gap-2 rounded-xl border-2 border-zinc-200 px-4 py-2 text-sm font-medium transition hover:bg-zinc-50 dark:border-zinc-600 dark:hover:bg-zinc-800"
                        :class="{ 'pointer-events-none opacity-50': busy }"
                    >
                        <Upload class="h-4 w-4" /> Enviar pacote
                        <input type="file" accept=".zip,application/zip" class="hidden" @change="onFile" />
                    </label>
                </div>
            </div>

            <div v-if="current" class="rounded-xl border border-zinc-200 p-4 text-sm dark:border-zinc-700">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="font-medium">{{ current.original_name }}</span>
                    <span class="text-xs text-zinc-500">{{ STATUS_LABELS[current.status] || current.status }}</span>
                </div>
                <p v-if="current.error" class="mt-2 text-red-600">{{ current.error }}</p>
                <template v-if="current.status === 'ready' && current.summary">
                    <ul class="mt-2 list-inside list-disc text-xs text-zinc-600 dark:text-zinc-400">
                        <li v-for="p in current.summary.products" :key="p.key">
                            {{ p.name }} — {{ p.offers }} ofertas, {{ p.plans }} planos, {{ p.order_bumps }} order bumps
                        </li>
                    </ul>
                    <p class="mt-1 text-xs text-zinc-500">{{ current.summary.assets }} arquivos de mídia.</p>
                    <div class="mt-3 flex gap-2">
                        <Button type="button" :disabled="busy" @click="request('import')">Confirmar importação</Button>
                        <Button type="button" variant="outline" :disabled="busy" @click="cancelImport">Cancelar</Button>
                    </div>
                </template>
                <ul v-if="current.status === 'completed' && current.result" class="mt-2 text-xs text-zinc-600 dark:text-zinc-400">
                    <li v-for="p in current.result.products" :key="p.id">Criado: {{ p.name }}</li>
                    <li v-for="(w, i) in current.result.warnings || []" :key="`w${i}`" class="text-amber-700 dark:text-amber-400">• {{ w }}</li>
                </ul>
            </div>

            <p v-if="actionError" class="text-sm font-medium text-red-600 dark:text-red-400">{{ actionError }}</p>

            <div v-if="imports.length" class="text-xs text-zinc-500">
                <p class="font-medium text-zinc-700 dark:text-zinc-300">Importações recentes nesta conta</p>
                <ul class="mt-1 space-y-0.5">
                    <li v-for="i in imports" :key="i.uuid">
                        {{ i.original_name }} · {{ STATUS_LABELS[i.status] || i.status }} · {{ i.created_at ? new Date(i.created_at).toLocaleString('pt-BR') : '' }}
                    </li>
                </ul>
            </div>
        </div>

        <PlatformStepUpModal
            :open="stepUp !== null"
            :title="stepUp === 'export' ? 'Exportar produto do seller' : 'Importar produtos na conta do seller'"
            description="Informe o código 2FA para continuar."
            confirm-label="Continuar"
            :loading="busy"
            :require-totp="totpEnabled"
            @close="stepUp = null"
            @confirm="(p) => (stepUp === 'export' ? doExport(p) : doConfirm(p))"
        />
    </section>
</template>
