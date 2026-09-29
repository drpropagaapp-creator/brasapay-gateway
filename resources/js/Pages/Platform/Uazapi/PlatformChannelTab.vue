<script setup>
import { computed, ref, watch } from 'vue';
import axios from 'axios';
import Button from '@/components/ui/Button.vue';
import Toggle from '@/components/ui/Toggle.vue';
import PlatformStepUpModal from '@/components/platform/PlatformStepUpModal.vue';
const props = defineProps({
    platform: { type: Object, required: true },
});

const platformTotpEnabled = computed(() => props.platform.platform_totp_enabled ?? false);

const channel = ref({ ...props.platform.channel });
const templates = ref(JSON.parse(JSON.stringify(props.platform.templates || [])));
const recentDispatches = ref([...(props.platform.recent_dispatches || [])]);
const recentCampaigns = ref([...(props.platform.recent_campaigns || [])]);

const instanceToken = ref('');
const saving = ref(false);
const connecting = ref(false);
const polling = ref(false);
const errorMessage = ref(null);
const successMessage = ref(null);

const testPhone = ref('');
const testMessage = ref('');
const testing = ref(false);

const broadcastMessage = ref('');
const broadcastDelay = ref(props.platform.campaign_defaults?.delay_seconds ?? 10);
const broadcastAccountStatus = ref('');
const broadcastKycStatus = ref('');
const previewCount = ref(null);
const previewLoading = ref(false);
const broadcastStepUpOpen = ref(false);
const broadcastLoading = ref(false);

watch(
    () => props.platform,
    (p) => {
        channel.value = { ...p.channel };
        templates.value = JSON.parse(JSON.stringify(p.templates || []));
        recentDispatches.value = [...(p.recent_dispatches || [])];
        recentCampaigns.value = [...(p.recent_campaigns || [])];
        broadcastDelay.value = p.campaign_defaults?.delay_seconds ?? 10;
    },
    { deep: true },
);

const docsUrl = computed(() =>
    channel.value.provider === 'evolution' ? props.platform.evolution_docs_url : props.platform.uazapi_docs_url,
);

const providerLabel = computed(() => (channel.value.provider === 'evolution' ? 'Evolution API' : 'Uazapi'));

function statusLabel(status) {
    return {
        connected: 'Conectado',
        connecting: 'Conectando',
        disconnected: 'Desconectado',
        hibernated: 'Hibernado',
    }[status] || status;
}

function channelPayload() {
    const payload = {
        provider: channel.value.provider,
        server_url: channel.value.server_url,
        instance_name: channel.value.instance_name || null,
        is_active: channel.value.is_active,
    };
    if (instanceToken.value.trim()) {
        payload.instance_token = instanceToken.value.trim();
    }
    return payload;
}

function applyChannelResponse(data) {
    if (data.channel) {
        channel.value = { ...data.channel };
    }
    if (data.templates) {
        templates.value = data.templates;
    }
    if (data.recent_dispatches) {
        recentDispatches.value = data.recent_dispatches;
    }
    if (data.campaign) {
        recentCampaigns.value = [data.campaign, ...recentCampaigns.value].slice(0, 5);
    }
}

async function saveChannel() {
    saving.value = true;
    errorMessage.value = null;
    successMessage.value = null;
    try {
        const { data } = await axios.put('/plataforma/whatsapp-canal/channel', channelPayload());
        applyChannelResponse(data);
        instanceToken.value = '';
        successMessage.value = 'Credenciais salvas.';
    } catch (e) {
        applyChannelResponse(e.response?.data || {});
        errorMessage.value = e.response?.data?.message || 'Não foi possível salvar.';
    } finally {
        saving.value = false;
    }
}

async function connect() {
    connecting.value = true;
    errorMessage.value = null;
    successMessage.value = null;
    try {
        if (!channel.value.has_credentials || instanceToken.value.trim() || channel.value.server_url) {
            const saved = await axios.put('/plataforma/whatsapp-canal/channel', channelPayload());
            applyChannelResponse(saved.data);
            instanceToken.value = '';
        }
        const { data } = await axios.post('/plataforma/whatsapp-canal/channel/connect');
        applyChannelResponse(data);
        successMessage.value = channel.value.connected
            ? 'WhatsApp conectado.'
            : 'Leia o QR Code no celular (Aparelhos conectados).';
        if (!channel.value.connected) {
            startPolling();
        }
    } catch (e) {
        applyChannelResponse(e.response?.data || {});
        errorMessage.value = e.response?.data?.message || 'Falha ao conectar.';
    } finally {
        connecting.value = false;
    }
}

async function refreshStatus() {
    polling.value = true;
    try {
        const { data } = await axios.get('/plataforma/whatsapp-canal/channel/status');
        applyChannelResponse(data);
        if (channel.value.connected) {
            successMessage.value = 'WhatsApp conectado.';
        }
    } catch (e) {
        errorMessage.value = e.response?.data?.message || 'Não foi possível atualizar o status.';
    } finally {
        polling.value = false;
    }
}

let pollTimer = null;
function startPolling() {
    clearInterval(pollTimer);
    pollTimer = setInterval(async () => {
        if (channel.value.connected) {
            clearInterval(pollTimer);
            return;
        }
        await refreshStatus();
    }, 8000);
}

async function disconnect() {
    if (!confirm('Desconectar o WhatsApp da plataforma?')) return;
    try {
        const { data } = await axios.post('/plataforma/whatsapp-canal/channel/disconnect');
        applyChannelResponse(data);
        successMessage.value = 'Desconectado.';
    } catch (e) {
        errorMessage.value = e.response?.data?.message || 'Falha ao desconectar.';
    }
}

async function saveTemplates() {
    saving.value = true;
    errorMessage.value = null;
    try {
        const { data } = await axios.put('/plataforma/whatsapp-canal/templates', { templates: templates.value });
        templates.value = data.templates;
        successMessage.value = 'Mensagens automáticas salvas.';
    } catch (e) {
        errorMessage.value = e.response?.data?.message || 'Não foi possível salvar os templates.';
    } finally {
        saving.value = false;
    }
}

async function loadPreview() {
    previewLoading.value = true;
    previewCount.value = null;
    try {
        const { data } = await axios.post('/plataforma/whatsapp-canal/broadcast/preview', {
            account_status: broadcastAccountStatus.value || undefined,
            kyc_status: broadcastKycStatus.value || undefined,
        });
        previewCount.value = data.count;
    } catch (e) {
        errorMessage.value = e.response?.data?.message || 'Falha na prévia.';
    } finally {
        previewLoading.value = false;
    }
}

function openBroadcastConfirm() {
    if (!broadcastMessage.value.trim()) {
        errorMessage.value = 'Informe a mensagem da campanha.';
        return;
    }
    broadcastStepUpOpen.value = true;
}

async function launchBroadcast(stepUp) {
    broadcastLoading.value = true;
    errorMessage.value = null;
    try {
        const { data } = await axios.post('/plataforma/whatsapp-canal/broadcast', {
            message: broadcastMessage.value,
            delay_seconds: broadcastDelay.value,
            account_status: broadcastAccountStatus.value || undefined,
            kyc_status: broadcastKycStatus.value || undefined,
            totp_code: stepUp.totp_code || undefined,
            manual_approval_pin: stepUp.manual_approval_pin || undefined,
        });
        applyChannelResponse(data);
        broadcastStepUpOpen.value = false;
        successMessage.value = `Campanha enfileirada (${data.campaign?.queued_count ?? 0} destinatários).`;
    } catch (e) {
        const msg = e.response?.data?.errors?.message?.[0]
            || e.response?.data?.message
            || Object.values(e.response?.data?.errors || {}).flat()[0]
            || 'Falha ao disparar campanha.';
        errorMessage.value = msg;
        if (e.response?.status !== 422) {
            broadcastStepUpOpen.value = false;
        }
    } finally {
        broadcastLoading.value = false;
    }
}

async function sendTest() {
    testing.value = true;
    errorMessage.value = null;
    try {
        await axios.post('/plataforma/whatsapp-canal/test', {
            phone: testPhone.value,
            message: testMessage.value || undefined,
        });
        successMessage.value = 'Mensagem de teste enviada.';
    } catch (e) {
        errorMessage.value = e.response?.data?.message || 'Falha no teste.';
    } finally {
        testing.value = false;
    }
}

const templateVariables = computed(() => props.platform.template_variables || []);
</script>

<template>
    <div class="space-y-6">
        <p class="text-sm text-zinc-600 dark:text-zinc-400">
            Número e credenciais exclusivos da plataforma (cadastro, KYC e avisos). Separado da recuperação de carrinho dos infoprodutores.
            <a
                v-if="docsUrl"
                :href="docsUrl"
                target="_blank"
                rel="noopener noreferrer"
                class="ml-1 text-[var(--color-primary)] hover:underline"
            >Documentação {{ providerLabel }}</a>
        </p>

        <p v-if="errorMessage" class="rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700 dark:bg-red-950/40 dark:text-red-300">{{ errorMessage }}</p>
        <p v-if="successMessage" class="rounded-lg bg-emerald-50 px-3 py-2 text-sm text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200">{{ successMessage }}</p>

        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
            <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500">Conexão</h2>
            <div class="grid gap-4 md:grid-cols-2">
                <label class="block text-sm">
                    <span class="mb-1 block text-zinc-600 dark:text-zinc-400">Provedor</span>
                    <select v-model="channel.provider" class="w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 dark:border-zinc-600 dark:bg-zinc-900">
                        <option value="uazapi">Uazapi</option>
                        <option value="evolution">Evolution API</option>
                    </select>
                </label>
                <label class="flex items-end gap-2 pb-2 text-sm">
                    <Toggle v-model="channel.is_active" />
                    <span>Canal ativo</span>
                </label>
                <label class="block text-sm md:col-span-2">
                    <span class="mb-1 block text-zinc-600 dark:text-zinc-400">URL do servidor</span>
                    <input v-model="channel.server_url" type="url" class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-900" placeholder="https://..." />
                </label>
                <label v-if="channel.provider === 'evolution'" class="block text-sm md:col-span-2">
                    <span class="mb-1 block text-zinc-600 dark:text-zinc-400">Nome da instância</span>
                    <input v-model="channel.instance_name" type="text" class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-900" />
                </label>
                <label class="block text-sm md:col-span-2">
                    <span class="mb-1 block text-zinc-600 dark:text-zinc-400">Token da instância</span>
                    <input
                        v-model="instanceToken"
                        type="password"
                        autocomplete="off"
                        class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-900"
                        :placeholder="channel.has_token ? '•••••• (deixe vazio para manter)' : 'Cole o token'"
                    />
                </label>
            </div>
            <div class="mt-4 flex flex-wrap gap-2">
                <Button type="button" :disabled="saving" @click="saveChannel">Salvar credenciais</Button>
                <Button type="button" variant="secondary" :disabled="connecting" @click="connect">Conectar / QR Code</Button>
                <Button type="button" variant="secondary" :disabled="polling" @click="refreshStatus">Atualizar status</Button>
                <Button type="button" variant="ghost" @click="disconnect">Desconectar</Button>
            </div>
            <div class="mt-4 flex flex-wrap gap-4 text-sm">
                <span>Status: <strong>{{ statusLabel(channel.status) }}</strong></span>
                <span v-if="channel.phone">Número: {{ channel.phone }}</span>
                <span v-if="channel.profile_name">Perfil: {{ channel.profile_name }}</span>
                <span v-if="channel.last_error" class="text-red-600">{{ channel.last_error }}</span>
            </div>
            <div v-if="channel.qrcode" class="mt-4 flex justify-center rounded-xl bg-white p-4">
                <img :src="channel.qrcode.startsWith('data:') ? channel.qrcode : `data:image/png;base64,${channel.qrcode}`" alt="QR Code WhatsApp" class="max-h-64" />
            </div>
            <p v-if="channel.paircode" class="mt-2 text-center font-mono text-lg">{{ channel.paircode }}</p>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
            <h2 class="mb-2 text-sm font-semibold uppercase tracking-wide text-zinc-500">Eventos automáticos</h2>
            <p class="mb-4 text-xs text-zinc-500">Variáveis: {{ templateVariables.join(', ') }}</p>
            <div v-for="(tpl, idx) in templates" :key="tpl.event_key" class="mb-6 border-b border-zinc-100 pb-6 last:border-0 dark:border-zinc-700">
                <div class="mb-2 flex items-center justify-between gap-2">
                    <span class="font-medium text-zinc-900 dark:text-white">{{ tpl.label }}</span>
                    <label class="flex items-center gap-2 text-sm">
                        <Toggle v-model="templates[idx].enabled" />
                        Ativo
                    </label>
                </div>
                <textarea
                    v-model="templates[idx].message"
                    rows="3"
                    class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                />
            </div>
            <Button type="button" :disabled="saving" @click="saveTemplates">Salvar mensagens</Button>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
            <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500">Campanha em massa</h2>
            <p class="mb-3 text-xs text-zinc-500">Máx. {{ platform.campaign_defaults?.max_recipients ?? 500 }} destinatários. Exige 2FA ou PIN de operação.</p>
            <textarea v-model="broadcastMessage" rows="4" class="mb-3 w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900" placeholder="Mensagem (use as mesmas variáveis quando aplicável)" />
            <div class="mb-3 grid gap-3 md:grid-cols-3">
                <label class="text-sm">
                    <span class="mb-1 block text-zinc-500">Delay entre envios (s)</span>
                    <input v-model.number="broadcastDelay" type="number" min="5" max="120" class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-900" />
                </label>
                <label class="text-sm">
                    <span class="mb-1 block text-zinc-500">Status da conta</span>
                    <select v-model="broadcastAccountStatus" class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-900">
                        <option value="">Todos</option>
                        <option value="approved">Aprovado</option>
                        <option value="pending">Pendente</option>
                        <option value="rejected">Rejeitado</option>
                    </select>
                </label>
                <label class="text-sm">
                    <span class="mb-1 block text-zinc-500">KYC</span>
                    <select v-model="broadcastKycStatus" class="w-full rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-900">
                        <option value="">Todos</option>
                        <option value="approved">Aprovado</option>
                        <option value="pending_review">Em análise</option>
                        <option value="rejected">Recusado</option>
                        <option value="not_submitted">Não enviado</option>
                    </select>
                </label>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <Button type="button" variant="secondary" :disabled="previewLoading" @click="loadPreview">Prévia de destinatários</Button>
                <span v-if="previewCount !== null" class="text-sm text-zinc-600">{{ previewCount }} infoprodutor(es)</span>
                <Button type="button" @click="openBroadcastConfirm">Disparar campanha</Button>
            </div>
            <ul v-if="recentCampaigns.length" class="mt-4 space-y-2 text-sm">
                <li v-for="c in recentCampaigns" :key="c.id" class="rounded-lg bg-zinc-50 px-3 py-2 dark:bg-zinc-900/50">
                    #{{ c.id }} — {{ c.status }} — {{ c.queued_count }} na fila — delay {{ c.delay_seconds }}s
                </li>
            </ul>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
            <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500">Teste rápido</h2>
            <div class="grid gap-3 md:grid-cols-2">
                <input v-model="testPhone" type="text" placeholder="WhatsApp com DDD" class="rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-900" />
                <input v-model="testMessage" type="text" placeholder="Mensagem (opcional)" class="rounded-lg border border-zinc-300 px-3 py-2 dark:border-zinc-600 dark:bg-zinc-900" />
            </div>
            <Button type="button" class="mt-3" :disabled="testing" @click="sendTest">Enviar teste</Button>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800">
            <h2 class="mb-4 text-sm font-semibold uppercase tracking-wide text-zinc-500">Últimos envios (plataforma)</h2>
            <div v-if="recentDispatches.length" class="overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-zinc-200 text-xs uppercase text-zinc-500 dark:border-zinc-700">
                            <th class="px-2 py-2">ID</th>
                            <th class="px-2 py-2">Evento</th>
                            <th class="px-2 py-2">Status</th>
                            <th class="px-2 py-2">Telefone</th>
                            <th class="px-2 py-2">Erro</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="row in recentDispatches" :key="row.id" class="border-b border-zinc-100 dark:border-zinc-800">
                            <td class="px-2 py-2 font-mono text-xs">{{ row.id }}</td>
                            <td class="px-2 py-2">{{ row.event_type }}</td>
                            <td class="px-2 py-2">{{ row.status }}</td>
                            <td class="px-2 py-2">{{ row.phone }}</td>
                            <td class="px-2 py-2 text-xs text-red-600">{{ row.error || '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p v-else class="text-sm text-zinc-500">Nenhum envio ainda.</p>
        </section>

        <PlatformStepUpModal
            :open="broadcastStepUpOpen"
            title="Confirmar campanha WhatsApp"
            description="Informe o código 2FA ou o PIN de operação para enfileirar os envios."
            :require-totp="platformTotpEnabled"
            :require-pin="!platformTotpEnabled"
            confirm-label="Disparar"
            :loading="broadcastLoading"
            @close="broadcastStepUpOpen = false"
            @confirm="launchBroadcast"
        />
    </div>
</template>
