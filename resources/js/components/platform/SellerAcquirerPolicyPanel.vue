<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import Button from '@/components/ui/Button.vue';
import PlatformStepUpModal from '@/components/platform/PlatformStepUpModal.vue';
import { ArrowDown, ArrowUp, X } from 'lucide-vue-next';

/**
 * Adquirentes e métodos autorizados para um seller (só admin). A política
 * vence qualquer preferência do produto, da API ou da ordem do seller.
 */
const props = defineProps({
    merchantId: { type: [Number, String], required: true },
});

const page = usePage();
const METHOD_LABELS = {
    pix: 'PIX',
    card: 'Cartão',
    boleto: 'Boleto',
    pix_auto: 'PIX automático',
    open_finance: 'Open Finance',
    paypal: 'PayPal',
    wallet: 'Apple Pay / Google Pay',
};
const MODE_LABELS = { inherit: 'Herdar plataforma', restricted: 'Somente as autorizadas', disabled: 'Desativado' };

const loading = ref(true);
const loadError = ref('');
const saving = ref(false);
const stepUpOpen = ref(false);
const methods = ref([]);
const logs = ref([]);
const reason = ref('');
const showLogs = ref(false);
const draft = reactive({});

const totpEnabled = computed(() => Boolean(page.props.auth?.user?.totp_enabled));
const dirty = computed(() =>
    methods.value.some((m) => JSON.stringify(normalizedRule(draft[m.key])) !== JSON.stringify(normalizedRule(m.rule)))
);

function normalizedRule(rule) {
    const mode = rule?.mode || 'inherit';
    return { mode, gateways: mode === 'restricted' ? [...(rule?.gateways || [])] : [] };
}

async function load() {
    loading.value = true;
    loadError.value = '';
    try {
        const { data } = await axios.get(`/plataforma/usuarios/${props.merchantId}/adquirentes`);
        methods.value = data.methods || [];
        logs.value = data.logs || [];
        for (const m of methods.value) {
            draft[m.key] = normalizedRule(m.rule);
        }
    } catch (e) {
        loadError.value = e?.response?.data?.message || 'Não foi possível carregar as adquirentes deste seller.';
    } finally {
        loading.value = false;
    }
}

function capableName(method, slug) {
    return method.capable.find((g) => g.slug === slug)?.name || slug;
}

function isConnected(method, slug) {
    return Boolean(method.capable.find((g) => g.slug === slug)?.connected);
}

function available(method) {
    const chosen = draft[method.key]?.gateways || [];
    return method.capable.filter((g) => !chosen.includes(g.slug));
}

function addGateway(method, slug) {
    if (!slug) return;
    draft[method.key].gateways.push(slug);
}

function removeGateway(method, index) {
    draft[method.key].gateways.splice(index, 1);
}

function move(method, index, delta) {
    const list = draft[method.key].gateways;
    const target = index + delta;
    if (target < 0 || target >= list.length) return;
    [list[index], list[target]] = [list[target], list[index]];
}

function setMode(method, mode) {
    draft[method.key].mode = mode;
    if (mode === 'restricted' && draft[method.key].gateways.length === 0) {
        const first = method.capable.find((g) => g.connected) || method.capable[0];
        if (first) draft[method.key].gateways.push(first.slug);
    }
}

const invalidRows = computed(() =>
    methods.value.filter((m) => draft[m.key]?.mode === 'restricted' && draft[m.key].gateways.length === 0).map((m) => m.key)
);

function requestSave() {
    if (!dirty.value || invalidRows.value.length) return;
    if (totpEnabled.value) {
        stepUpOpen.value = true;
        return;
    }
    submit({});
}

function submit({ totp_code: totpCode } = {}) {
    saving.value = true;
    const payload = {};
    for (const m of methods.value) {
        const rule = draft[m.key];
        if (rule.mode !== 'inherit') payload[m.key] = { mode: rule.mode, gateways: rule.gateways };
    }
    router.put(
        `/plataforma/usuarios/${props.merchantId}/adquirentes`,
        { methods: payload, reason: reason.value || null, totp_code: totpCode || undefined },
        {
            preserveScroll: true,
            onSuccess: () => {
                stepUpOpen.value = false;
                reason.value = '';
                load();
            },
            onFinish: () => {
                saving.value = false;
            },
        }
    );
}

function describe(rule) {
    if (!rule || rule.mode === 'inherit') return 'Herdado';
    if (rule.mode === 'disabled') return 'Desativado';
    return (rule.gateways || []).join(' → ');
}

function changeSummary(log) {
    const keys = new Set([...Object.keys(log.before || {}), ...Object.keys(log.after || {})]);
    return [...keys]
        .map((k) => `${METHOD_LABELS[k] || k}: ${describe(log.before?.[k])} → ${describe(log.after?.[k])}`)
        .join(' · ');
}

onMounted(load);
</script>

<template>
    <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="font-semibold">Adquirentes e métodos autorizados</h2>
                <p class="mt-1 max-w-2xl text-xs text-zinc-500">
                    Define o que este seller pode usar. Vale acima de qualquer preferência do produto, da API ou da
                    ordem acima: uma adquirente fora desta lista nunca é tentada, nem como contingência.
                </p>
            </div>
            <button v-if="logs.length" type="button" class="text-xs text-[var(--color-primary)] hover:underline" @click="showLogs = !showLogs">
                {{ showLogs ? 'Ocultar histórico' : `Histórico (${logs.length})` }}
            </button>
        </div>

        <p v-if="loading" class="mt-4 text-sm text-zinc-500">Carregando…</p>
        <p v-else-if="loadError" class="mt-4 text-sm text-red-600">{{ loadError }}</p>

        <div v-else class="mt-4 divide-y divide-zinc-100 dark:divide-zinc-800">
            <div v-for="method in methods" :key="method.key" class="grid gap-3 py-4 lg:grid-cols-[180px_220px_minmax(0,1fr)]">
                <div>
                    <p class="text-sm font-medium">{{ METHOD_LABELS[method.key] || method.key }}</p>
                    <p class="mt-0.5 text-[11px] text-zinc-500">
                        Efetivo hoje:
                        <span v-if="method.effective.length" class="font-medium text-zinc-700 dark:text-zinc-300">{{ method.effective.map((s) => capableName(method, s)).join(' → ') }}</span>
                        <span v-else class="font-medium text-red-600">indisponível</span>
                    </p>
                </div>

                <label class="text-xs">
                    <span class="sr-only">Modo para {{ METHOD_LABELS[method.key] }}</span>
                    <select
                        :value="draft[method.key].mode"
                        class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800"
                        @change="setMode(method, $event.target.value)"
                    >
                        <option v-for="(label, mode) in MODE_LABELS" :key="mode" :value="mode">{{ label }}</option>
                    </select>
                </label>

                <div>
                    <p v-if="draft[method.key].mode === 'inherit'" class="pt-2 text-xs text-zinc-500">Segue a ordem da plataforma e as preferências do seller.</p>
                    <p v-else-if="draft[method.key].mode === 'disabled'" class="pt-2 text-xs text-zinc-500">O seller não consegue cobrar por este método em nenhum canal.</p>
                    <div v-else class="space-y-2">
                        <ol class="space-y-1.5">
                            <li
                                v-for="(slug, idx) in draft[method.key].gateways"
                                :key="slug"
                                class="flex items-center gap-2 rounded-lg border border-zinc-200 px-3 py-1.5 text-sm dark:border-zinc-700"
                            >
                                <span class="w-20 shrink-0 text-[11px] text-zinc-500">{{ idx === 0 ? 'Principal' : `Contingência ${idx}` }}</span>
                                <span class="flex-1">{{ capableName(method, slug) }}</span>
                                <span v-if="!isConnected(method, slug)" class="rounded bg-amber-100 px-1.5 py-0.5 text-[10px] text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">não conectada</span>
                                <button type="button" class="rounded p-1 text-zinc-500 hover:bg-zinc-100 disabled:opacity-30 dark:hover:bg-zinc-800" :disabled="idx === 0" :aria-label="`Subir ${capableName(method, slug)}`" @click="move(method, idx, -1)"><ArrowUp class="h-3.5 w-3.5" /></button>
                                <button type="button" class="rounded p-1 text-zinc-500 hover:bg-zinc-100 disabled:opacity-30 dark:hover:bg-zinc-800" :disabled="idx === draft[method.key].gateways.length - 1" :aria-label="`Descer ${capableName(method, slug)}`" @click="move(method, idx, 1)"><ArrowDown class="h-3.5 w-3.5" /></button>
                                <button type="button" class="rounded p-1 text-zinc-500 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/40" :aria-label="`Remover ${capableName(method, slug)}`" @click="removeGateway(method, idx)"><X class="h-3.5 w-3.5" /></button>
                            </li>
                        </ol>
                        <select
                            v-if="available(method).length"
                            class="w-full rounded-lg border border-dashed border-zinc-300 bg-white px-3 py-1.5 text-xs text-zinc-600 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300"
                            :value="''"
                            @change="addGateway(method, $event.target.value); $event.target.value = ''"
                        >
                            <option value="">+ Autorizar outra adquirente</option>
                            <option v-for="g in available(method)" :key="g.slug" :value="g.slug">{{ g.name }}{{ g.connected ? '' : ' (não conectada)' }}</option>
                        </select>
                        <p v-if="invalidRows.includes(method.key)" class="text-xs text-red-600">Autorize ao menos uma adquirente ou desative o método.</p>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="!loading && !loadError" class="mt-2 flex flex-wrap items-end gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800">
            <label class="min-w-[240px] flex-1 text-xs">
                <span class="text-zinc-600 dark:text-zinc-400">Motivo da alteração (fica no histórico)</span>
                <input v-model="reason" type="text" maxlength="500" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800" placeholder="Ex.: conta nova, só CajuPay no PIX" />
            </label>
            <Button type="button" :disabled="!dirty || saving || invalidRows.length > 0" @click="requestSave">
                {{ saving ? 'Salvando…' : 'Salvar adquirentes' }}
            </Button>
        </div>

        <div v-if="showLogs" class="mt-4 space-y-2 rounded-xl bg-zinc-50 p-3 text-xs dark:bg-zinc-800/50">
            <div v-for="log in logs" :key="log.id" class="border-b border-zinc-200 pb-2 last:border-0 last:pb-0 dark:border-zinc-700">
                <p class="text-zinc-500">{{ new Date(log.created_at).toLocaleString('pt-BR') }} · {{ log.actor || 'Sistema' }}</p>
                <p class="mt-0.5 text-zinc-700 dark:text-zinc-300">{{ changeSummary(log) || 'Sem alteração' }}</p>
                <p v-if="log.reason" class="mt-0.5 italic text-zinc-500">“{{ log.reason }}”</p>
            </div>
        </div>

        <PlatformStepUpModal
            :open="stepUpOpen"
            title="Alterar adquirentes do seller"
            description="Informe o código 2FA para aplicar a nova política de adquirência."
            confirm-label="Aplicar"
            :loading="saving"
            :require-totp="totpEnabled"
            @close="stepUpOpen = false"
            @confirm="submit"
        />
    </section>
</template>
