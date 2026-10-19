<script setup>
import { computed, ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';
import { Mail, Settings2, Send, Plus, FileEdit, CheckCircle2, XCircle, Inbox } from 'lucide-vue-next';

defineOptions({ layout: LayoutPlatform });

const props = defineProps({
    campaigns: { type: Array, default: () => [] },
    email_configured: { type: Boolean, default: false },
    cloud_mode: { type: Boolean, default: false },
    cron_instructions: { type: String, default: '' },
    app_url: { type: String, default: '' },
    cron_url: { type: String, default: null },
    base_path: { type: String, default: '' },
    schedule_ok: { type: Boolean, default: false },
    queue_ok: { type: Boolean, default: false },
    section: { type: String, default: 'marketing' },
    email_filters: { type: Object, default: () => ({ status: null, q: null }) },
    sent_emails: { type: Object, default: () => ({ data: [], links: [], total: 0 }) },
});

const section = ref(props.section === 'enviados' ? 'enviados' : 'marketing');
const activeTab = ref('campanhas');
const searchQ = ref(props.email_filters?.q || '');
const statusFilter = ref(props.email_filters?.status || '');

watch(
    () => props.section,
    (value) => {
        section.value = value === 'enviados' ? 'enviados' : 'marketing';
    }
);

watch(
    () => props.email_filters,
    (filters) => {
        searchQ.value = filters?.q || '';
        statusFilter.value = filters?.status || '';
    }
);

const sentRows = computed(() => (Array.isArray(props.sent_emails?.data) ? props.sent_emails.data : []));
const paginationLinks = computed(() => (Array.isArray(props.sent_emails?.links) ? props.sent_emails.links : []));

const statusLabel = (status) => {
    const map = { draft: 'Rascunho', sending: 'Enviando', sent: 'Enviado', cancelled: 'Cancelado' };
    return map[status] ?? status;
};

const deliveryStatusLabel = (status) => {
    const map = {
        opened: 'Aberto',
        sent: 'Enviado',
        failed: 'Falha',
        sending: 'Enviando',
    };
    return map[status] ?? status;
};

function confirmSend(campaign) {
    if (!props.email_configured) return;
    if (!confirm(`Disparar campanha "${campaign.name}"? Os e-mails serão enviados em lotes de 30 por minuto.`)) return;
    router.post(`/plataforma/email-marketing/${campaign.id}/send`);
}

function openSection(name) {
    section.value = name;
    const query = name === 'enviados' ? listingQuery() : {};
    router.get('/plataforma/email-marketing', query, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function listingQuery(overrides = {}) {
    return {
        aba: 'enviados',
        q: (overrides.q !== undefined ? overrides.q : searchQ.value)?.trim() || undefined,
        status: (overrides.status !== undefined ? overrides.status : statusFilter.value) || undefined,
    };
}

function applyEmailFilters() {
    section.value = 'enviados';
    router.get('/plataforma/email-marketing', listingQuery(), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function formatDateTime(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleString('pt-BR');
}

function deliveryClass(status) {
    if (status === 'opened') return 'bg-sky-50 text-sky-800 dark:bg-sky-950/40 dark:text-sky-200';
    if (status === 'sent') return 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-200';
    if (status === 'failed') return 'bg-red-50 text-red-800 dark:bg-red-950/40 dark:text-red-200';
    return 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-200';
}
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h1 class="text-2xl font-bold text-zinc-900 dark:text-white">Central de e-mails</h1>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                    Campanhas da plataforma e o histórico do que o sistema enviou.
                </p>
            </div>
            <div class="flex gap-2">
                <Link
                    v-if="section === 'marketing' && activeTab === 'campanhas'"
                    href="/plataforma/email-marketing/create"
                    class="inline-flex items-center gap-2 rounded-lg bg-[var(--color-primary)] px-4 py-2 text-sm font-medium text-white hover:opacity-90"
                >
                    <Plus class="h-4 w-4" />
                    Nova campanha
                </Link>
            </div>
        </div>

        <div class="flex gap-2 border-b border-zinc-200 dark:border-zinc-700">
            <button
                type="button"
                :class="[
                    'flex items-center gap-2 border-b-2 px-4 py-2 text-sm font-medium transition-colors',
                    section === 'marketing'
                        ? 'border-[var(--color-primary)] text-[var(--color-primary)]'
                        : 'border-transparent text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100',
                ]"
                @click="openSection('marketing')"
            >
                <Mail class="h-4 w-4" />
                E-mail Marketing
            </button>
            <button
                type="button"
                :class="[
                    'flex items-center gap-2 border-b-2 px-4 py-2 text-sm font-medium transition-colors',
                    section === 'enviados'
                        ? 'border-[var(--color-primary)] text-[var(--color-primary)]'
                        : 'border-transparent text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100',
                ]"
                @click="openSection('enviados')"
            >
                <Inbox class="h-4 w-4" />
                E-mails enviados
            </button>
        </div>

        <div v-show="section === 'marketing'" class="space-y-6">
        <div
            v-if="!email_configured"
            class="rounded-xl border border-amber-200 bg-amber-50 p-4 dark:border-amber-800 dark:bg-amber-950/30"
        >
            <p class="text-sm font-medium text-amber-800 dark:text-amber-200">
                Configure o e-mail (SMTP, Hostinger, SendGrid etc.) em
                <Link href="/plataforma/configuracoes" class="underline">Configurações &gt; E-mail</Link>
                para poder disparar campanhas.
            </p>
        </div>

        <div class="flex gap-2 border-b border-zinc-200 dark:border-zinc-700">
            <button
                type="button"
                :class="[
                    'flex items-center gap-2 border-b-2 px-4 py-2 text-sm font-medium transition-colors',
                    activeTab === 'campanhas'
                        ? 'border-[var(--color-primary)] text-[var(--color-primary)]'
                        : 'border-transparent text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100',
                ]"
                @click="activeTab = 'campanhas'"
            >
                <Mail class="h-4 w-4" />
                Campanhas
            </button>
            <button
                type="button"
                :class="[
                    'flex items-center gap-2 border-b-2 px-4 py-2 text-sm font-medium transition-colors',
                    activeTab === 'configuracao'
                        ? 'border-[var(--color-primary)] text-[var(--color-primary)]'
                        : 'border-transparent text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-100',
                ]"
                @click="activeTab = 'configuracao'"
            >
                <Settings2 class="h-4 w-4" />
                Configuração
            </button>
        </div>

        <div v-show="activeTab === 'campanhas'" class="space-y-4">
            <div
                v-if="cloud_mode"
                class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-200"
            >
                Se você estiver usando o Getfy em modo cloud, não é necessário configurar o cron; o envio já vem
                configurado automaticamente.
            </div>

            <div v-if="campaigns.length === 0" class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white p-8 text-center dark:bg-zinc-800/50">
                <p class="text-zinc-600 dark:text-zinc-400">Nenhuma campanha ainda.</p>
                <Link
                    href="/plataforma/email-marketing/create"
                    class="mt-4 inline-flex items-center justify-center rounded-lg bg-[var(--color-primary)] px-4 py-2 text-sm font-medium text-white hover:opacity-90"
                >
                    Criar primeira campanha
                </Link>
            </div>

            <div v-else class="overflow-hidden rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800/50">
                <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    <li
                        v-for="c in campaigns"
                        :key="c.id"
                        class="flex flex-col gap-2 p-4 sm:flex-row sm:items-center sm:justify-between"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="font-medium text-zinc-900 dark:text-white">{{ c.name }}</p>
                            <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ c.subject }}</p>
                            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                {{ statusLabel(c.status) }}
                                <template v-if="c.status === 'sending' || c.status === 'sent'">
                                    — {{ c.sent_count ?? 0 }} / {{ c.total_recipients ?? 0 }} enviados
                                </template>
                            </p>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            <Link v-if="c.status === 'draft'" :href="`/plataforma/email-marketing/${c.id}/edit`">
                                <Button variant="outline" size="sm" class="inline-flex items-center gap-1">
                                    <FileEdit class="h-3.5 w-3.5" />
                                    Editar
                                </Button>
                            </Link>
                            <Button
                                v-if="c.status === 'draft' && email_configured"
                                variant="primary"
                                size="sm"
                                class="inline-flex items-center gap-1"
                                @click="confirmSend(c)"
                            >
                                <Send class="h-3.5 w-3.5" />
                                Disparar
                            </Button>
                        </div>
                    </li>
                </ul>
            </div>
        </div>

        <div v-show="activeTab === 'configuracao'" class="space-y-6">
            <div
                v-if="cloud_mode"
                class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-800 dark:bg-emerald-950/30"
            >
                <p class="font-medium text-emerald-800 dark:text-emerald-200">Modo cloud</p>
                <p class="mt-1 text-sm text-emerald-700 dark:text-emerald-300">
                    Se você estiver usando o Getfy em modo cloud, não é necessário configurar o cron; o envio já vem
                    configurado automaticamente.
                </p>
            </div>

            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white p-4 dark:bg-zinc-800/50">
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Status</h2>
                <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
                    A plataforma verifica automaticamente se o cron e a fila estão rodando (atualizado a cada minuto).
                </p>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div
                        :class="[
                            'flex items-center gap-3 rounded-lg border p-4',
                            schedule_ok
                                ? 'border-emerald-200 bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-950/30'
                                : 'border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900/50',
                        ]"
                    >
                        <CheckCircle2
                            v-if="schedule_ok"
                            class="h-8 w-8 shrink-0 text-emerald-600 dark:text-emerald-400"
                        />
                        <XCircle
                            v-else
                            class="h-8 w-8 shrink-0 text-amber-600 dark:text-amber-400"
                        />
                        <div>
                            <p class="font-medium text-zinc-900 dark:text-white">Cron (agendador)</p>
                            <p class="text-sm" :class="schedule_ok ? 'text-emerald-700 dark:text-emerald-300' : 'text-zinc-600 dark:text-zinc-400'">
                                {{ schedule_ok ? 'Configurado e rodando' : 'Não detectado' }}
                            </p>
                        </div>
                    </div>
                    <div
                        :class="[
                            'flex items-center gap-3 rounded-lg border p-4',
                            queue_ok
                                ? 'border-emerald-200 bg-emerald-50 dark:border-emerald-800 dark:bg-emerald-950/30'
                                : 'border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900/50',
                        ]"
                    >
                        <CheckCircle2
                            v-if="queue_ok"
                            class="h-8 w-8 shrink-0 text-emerald-600 dark:text-emerald-400"
                        />
                        <XCircle
                            v-else
                            class="h-8 w-8 shrink-0 text-amber-600 dark:text-amber-400"
                        />
                        <div>
                            <p class="font-medium text-zinc-900 dark:text-white">Fila (queue worker)</p>
                            <p class="text-sm" :class="queue_ok ? 'text-emerald-700 dark:text-emerald-300' : 'text-zinc-600 dark:text-zinc-400'">
                                {{ queue_ok ? 'Rodando normalmente' : 'Não detectada' }}
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 bg-white p-4 dark:bg-zinc-800/50">
                <h2 class="text-lg font-semibold text-zinc-900 dark:text-white">Como configurar</h2>
                <p class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                    Para os e-mails serem enviados em lotes de 30 por minuto, configure o crontab para rodar o agendador a cada minuto e mantenha o worker de fila ativo. A linha abaixo deve ser adicionada ao crontab (<code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">crontab -e</code>), não executada no terminal.
                </p>
                <p class="mt-2 text-xs font-medium text-zinc-500 dark:text-zinc-400">Cron (uma vez por minuto):</p>
                <pre class="mt-1 overflow-x-auto rounded-lg bg-zinc-100 p-4 text-left font-mono text-sm text-zinc-800 dark:bg-zinc-900 dark:text-zinc-200">* * * * * cd {{ base_path || '/caminho/do/projeto' }} && php artisan schedule:run >> /dev/null 2>&1</pre>
                <p class="mt-3 text-xs font-medium text-zinc-500 dark:text-zinc-400">Fila (deixe rodando em outro terminal ou com Supervisor):</p>
                <pre class="mt-1 overflow-x-auto rounded-lg bg-zinc-100 p-4 text-left font-mono text-sm text-zinc-800 dark:bg-zinc-900 dark:text-zinc-200">php artisan queue:work</pre>
                <div v-if="cron_url" class="mt-4 space-y-2">
                    <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">
                        URL do cron (para ferramentas externas: cron-job.org, EasyCron, UptimeRobot etc.):
                    </p>
                    <div class="flex flex-wrap items-center gap-2">
                        <code class="rounded-lg bg-zinc-100 px-3 py-2 font-mono text-sm text-zinc-800 break-all dark:bg-zinc-900 dark:text-zinc-200">{{ cron_url }}</code>
                        <button
                            type="button"
                            class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700"
                            @click="navigator.clipboard?.writeText(cron_url)"
                        >
                            Copiar
                        </button>
                    </div>
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                        Configure a URL para ser chamada a cada minuto. Defina <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">CRON_SECRET</code> no .env para gerar o link.
                    </p>
                </div>
                <p v-else class="mt-4 text-xs text-zinc-500 dark:text-zinc-400">
                    Para usar a URL do cron, adicione <code class="rounded bg-zinc-200 px-1 dark:bg-zinc-700">CRON_SECRET=seu_token_secreto</code> no arquivo .env.
                </p>
                <p class="mt-4 text-sm text-zinc-600 dark:text-zinc-400">
                    URL da aplicação: <a :href="app_url" class="text-[var(--color-primary)] underline" target="_blank" rel="noopener">{{ app_url }}</a>
                </p>
            </div>
        </div>
        </div>

        <div v-show="section === 'enviados'" class="space-y-4">
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                Cada disparo do sistema aparece aqui. Aberto significa que o programa de e-mail carregou a imagem de confirmação. Alguns clientes bloqueiam imagem, e outros carregam sozinhos.
            </p>

            <form class="flex flex-col gap-3 sm:flex-row" @submit.prevent="applyEmailFilters">
                <input
                    v-model="searchQ"
                    type="search"
                    placeholder="E-mail, nome ou assunto"
                    class="w-full rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                />
                <select
                    v-model="statusFilter"
                    class="rounded-xl border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-900"
                >
                    <option value="">Todos os status</option>
                    <option value="sent">Enviado</option>
                    <option value="opened">Aberto</option>
                    <option value="failed">Falha</option>
                    <option value="sending">Enviando</option>
                </select>
                <Button type="submit" variant="outline" size="sm">Filtrar</Button>
            </form>

            <div v-if="sentRows.length === 0" class="rounded-xl border border-dashed border-zinc-200 px-4 py-10 text-center text-sm text-zinc-500 dark:border-zinc-700">
                Nenhum e-mail registrado ainda.
            </div>

            <div v-else class="hidden overflow-hidden rounded-xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800/50 md:block">
                <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                    <thead class="bg-zinc-50 dark:bg-zinc-900/40">
                        <tr class="text-left text-xs font-semibold uppercase text-zinc-500">
                            <th class="px-4 py-3">Data</th>
                            <th class="px-4 py-3">E-mail</th>
                            <th class="px-4 py-3">Cliente</th>
                            <th class="px-4 py-3">Assunto</th>
                            <th class="px-4 py-3">Tipo</th>
                            <th class="px-4 py-3">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                        <tr v-for="row in sentRows" :key="row.id">
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-zinc-700 dark:text-zinc-200">
                                {{ formatDateTime(row.sent_at || row.created_at) }}
                            </td>
                            <td class="px-4 py-3 text-sm text-zinc-800 dark:text-zinc-100">{{ row.recipient_email }}</td>
                            <td class="px-4 py-3 text-sm text-zinc-700 dark:text-zinc-200">
                                <p>{{ row.recipient_name || '—' }}</p>
                                <p v-if="row.recipient_role" class="text-xs text-zinc-500">{{ row.recipient_role }}</p>
                            </td>
                            <td class="max-w-[220px] truncate px-4 py-3 text-sm text-zinc-700 dark:text-zinc-200" :title="row.subject || ''">
                                {{ row.subject || '—' }}
                            </td>
                            <td class="px-4 py-3 text-sm text-zinc-600 dark:text-zinc-300">{{ row.mail_label || 'E-mail' }}</td>
                            <td class="px-4 py-3">
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="deliveryClass(row.status)">
                                    {{ deliveryStatusLabel(row.status) }}
                                </span>
                                <p v-if="row.status === 'failed' && row.error_message" class="mt-1 max-w-[220px] truncate text-xs text-red-600 dark:text-red-300" :title="row.error_message">
                                    {{ row.error_message }}
                                </p>
                                <p v-else-if="row.opened_at" class="mt-1 text-xs text-zinc-500">
                                    {{ formatDateTime(row.opened_at) }}
                                </p>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="sentRows.length" class="space-y-3 md:hidden">
                <article
                    v-for="row in sentRows"
                    :key="`m-${row.id}`"
                    class="rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900/40"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-semibold text-zinc-900 dark:text-white">{{ row.recipient_email }}</p>
                            <p class="mt-0.5 text-xs text-zinc-500">{{ row.recipient_name || 'Sem cadastro' }}<span v-if="row.recipient_role"> · {{ row.recipient_role }}</span></p>
                        </div>
                        <span class="shrink-0 rounded-full px-2 py-0.5 text-[11px] font-medium" :class="deliveryClass(row.status)">
                            {{ deliveryStatusLabel(row.status) }}
                        </span>
                    </div>
                    <p class="mt-2 text-sm text-zinc-700 dark:text-zinc-200">{{ row.subject || '—' }}</p>
                    <p class="mt-1 text-xs text-zinc-500">{{ row.mail_label || 'E-mail' }} · {{ formatDateTime(row.sent_at || row.created_at) }}</p>
                    <p v-if="row.status === 'failed' && row.error_message" class="mt-2 text-xs text-red-600 dark:text-red-300">{{ row.error_message }}</p>
                </article>
            </div>

            <div v-if="paginationLinks.length > 3" class="flex flex-wrap justify-center gap-2">
                <Link
                    v-for="(l, idx) in paginationLinks"
                    :key="idx"
                    :href="l.url || '#'"
                    class="rounded-lg px-3 py-1.5 text-sm"
                    :class="l.active ? 'bg-[var(--color-primary)]/20 font-semibold' : 'bg-zinc-100 dark:bg-zinc-800'"
                    :preserve-scroll="true"
                >
                    <span v-html="l.label" />
                </Link>
            </div>
        </div>
    </div>
</template>
