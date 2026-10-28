<script setup>
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import PnPageHeader from '@/components/paynext/PnPageHeader.vue';
import PnKpiCard from '@/components/paynext/PnKpiCard.vue';
import PnPanel from '@/components/paynext/PnPanel.vue';
import PnEmptyState from '@/components/paynext/PnEmptyState.vue';
import { Globe, Copy, Check, Lock, TriangleAlert, Palette, ShieldCheck, Plus, RefreshCw, Trash2, Clock, CircleCheck, Link2 } from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

/** Brasa Enterprise (seller): marca liberada pela plataforma e domínio próprio do checkout. */
const props = defineProps({
    account: { type: Object, default: null },
    branding: { type: Object, default: () => ({}) },
    domains: { type: Array, default: () => [] },
    app_host: { type: String, default: '' },
    can_manage: { type: Boolean, default: false },
});

const STATUS = { pending: 'Aguardando verificação', active: 'Ativo', failed: 'Desativado (verificação falhou)' };
const form = useForm({ host: '' });
function add() {
    form.post('/marca-e-dominio/dominios', { preserveScroll: true, onSuccess: () => form.reset() });
}
function verify(d) {
    router.post(`/marca-e-dominio/dominios/${d.id}/verificar`, {}, { preserveScroll: true });
}
function remove(d) {
    if (window.confirm(`Remover ${d.host}? O checkout deixa de responder nesse domínio.`)) router.delete(`/marca-e-dominio/dominios/${d.id}`, { preserveScroll: true });
}
function copy(text) {
    navigator.clipboard?.writeText(text);
}

/* Apresentação */
const STATUS_BADGE = { pending: 'pn-badge--warning', active: 'pn-badge--success', failed: 'pn-badge--error' };
const activeDomains = computed(() => props.domains.filter((d) => d.status === 'active').length);
const copied = ref('');
let copiedTimer = null;
function copyField(text, key) {
    copy(text);
    copied.value = key;
    clearTimeout(copiedTimer);
    copiedTimer = setTimeout(() => (copied.value = ''), 1500);
}
const fmtDateTime = (iso) => (iso ? new Date(iso).toLocaleString('pt-BR') : '');
</script>

<template>
    <div class="space-y-6">
        <PnPageHeader
            eyebrow="White-label"
            title="Marca e domínio"
            :icon="Globe"
            description="White-label muda só a apresentação: nome, cores e o domínio do checkout. Sua conta, saldo, saques e adquirentes continuam os mesmos."
        />

        <PnEmptyState v-if="!account" :icon="Lock" title="White-label não está ativo nesta conta." description="Ele é liberado pela equipe da plataforma." />
        <div v-else-if="account.status !== 'active'" class="flex items-start gap-3 rounded-[22px] bg-[var(--status-warning-bg)] p-4 text-sm text-[var(--status-warning-fg)]" role="status">
            <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />
            <p>White-label suspenso pela plataforma. Os domínios próprios não respondem enquanto isso.</p>
        </div>

        <template v-if="account">
            <!-- Indicadores -->
            <section class="grid grid-cols-2 gap-3 lg:grid-cols-3" aria-label="Resumo">
                <PnKpiCard label="Domínios" :value="domains.length" :icon="Link2" :caption="`limite de ${account.max_domains}`" :index="0" />
                <PnKpiCard label="Ativos" :value="activeDomains" :icon="CircleCheck" caption="verificados e respondendo" :index="1" />
                <PnKpiCard class="col-span-2 lg:col-span-1" label="White-label" :value="account.status === 'active' ? 'Ativo' : 'Suspenso'" :icon="ShieldCheck" caption="liberado pela plataforma" :index="2" />
            </section>

            <!-- Marca -->
            <PnPanel title="Marca" sub="Definida pela equipe da plataforma. Peça ajustes ao seu gerente de conta." :index="1">
                <template #right>
                    <Palette class="h-4 w-4 shrink-0 text-[var(--text-tertiary)]" aria-hidden="true" />
                </template>
                <dl class="grid gap-2 sm:grid-cols-3">
                    <div class="rounded-2xl bg-[var(--bg-muted)]/60 p-4">
                        <dt class="text-[11px] text-[var(--text-tertiary)]">Nome exibido</dt>
                        <dd class="mt-1 truncate text-sm font-medium text-[var(--text-primary)]">{{ branding.app_name || account.display_name || '—' }}</dd>
                    </div>
                    <div class="rounded-2xl bg-[var(--bg-muted)]/60 p-4">
                        <dt class="text-[11px] text-[var(--text-tertiary)]">Cor principal</dt>
                        <dd class="mt-1 flex items-center gap-2 text-sm font-medium text-[var(--text-primary)]">
                            <span v-if="branding.theme_primary" class="inline-block h-5 w-5 shrink-0 rounded-full ring-1 ring-[var(--border-strong)]" :style="{ background: branding.theme_primary }" aria-hidden="true" />
                            <span class="font-mono text-[13px]">{{ branding.theme_primary || '—' }}</span>
                        </dd>
                    </div>
                    <div class="min-w-0 rounded-2xl bg-[var(--bg-muted)]/60 p-4">
                        <dt class="text-[11px] text-[var(--text-tertiary)]">Logo</dt>
                        <dd class="mt-1 truncate text-sm text-[var(--text-primary)]" :title="branding.app_logo || ''">{{ branding.app_logo || '—' }}</dd>
                    </div>
                </dl>
            </PnPanel>

            <!-- Domínio -->
            <PnPanel title="Domínio do checkout" sub="Use um subdomínio seu. Nele abre só o checkout dos seus produtos." :index="2">
                <template #right>
                    <Globe class="h-4 w-4 shrink-0 text-[var(--text-tertiary)]" aria-hidden="true" />
                </template>

                <p class="text-sm text-[var(--text-secondary)]">
                    Exemplo: <code class="rounded-md bg-[var(--bg-muted)] px-1.5 py-0.5 text-[12.5px] text-[var(--text-primary)]">pagamento.suaempresa.com.br</code>
                </p>
                <ol class="mt-4 grid gap-2 md:grid-cols-3">
                    <li class="flex items-start gap-3 rounded-2xl bg-[var(--bg-muted)]/60 p-3">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-[var(--lime-300)] text-[11px] font-medium text-[var(--ink-900)]">1</span>
                        <span class="text-xs text-[var(--text-secondary)]">Crie o registro TXT mostrado abaixo do domínio.</span>
                    </li>
                    <li class="flex items-start gap-3 rounded-2xl bg-[var(--bg-muted)]/60 p-3">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-[var(--lime-300)] text-[11px] font-medium text-[var(--ink-900)]">2</span>
                        <span class="min-w-0 text-xs text-[var(--text-secondary)]">
                            Aponte o domínio para <code class="break-all rounded bg-[var(--glass-bg-strong)] px-1 text-[var(--text-primary)]">{{ app_host }}</code> por um proxy com HTTPS (ex.: Cloudflare com proxy ligado).
                        </span>
                    </li>
                    <li class="flex items-start gap-3 rounded-2xl bg-[var(--bg-muted)]/60 p-3">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-[var(--lime-300)] text-[11px] font-medium text-[var(--ink-900)]">3</span>
                        <span class="text-xs text-[var(--text-secondary)]">Clique em verificar.</span>
                    </li>
                </ol>

                <div v-if="can_manage" class="mt-5 flex flex-wrap items-end gap-2">
                    <div class="flex min-w-0 flex-1 basis-60 flex-col gap-1.5">
                        <label for="domain-host" class="text-xs text-[var(--text-secondary)]">Novo domínio</label>
                        <input id="domain-host" v-model="form.host" placeholder="pagamento.suaempresa.com.br" maxlength="200" autocomplete="off" spellcheck="false"
                            class="h-11 w-full rounded-full border border-[var(--border-strong)] bg-[var(--glass-bg)] px-4 text-sm text-[var(--text-primary)] outline-none transition focus:ring-4 focus:ring-[var(--focus-ring)]" />
                    </div>
                    <button type="button" class="pn-btn pn-btn--primary !h-11 !w-auto" :disabled="form.processing || !form.host" @click="add">
                        <Plus class="h-4 w-4" aria-hidden="true" /> Adicionar
                    </button>
                </div>
                <p v-if="form.errors.host" class="mt-2 text-sm text-[var(--status-error-fg)]" role="alert">{{ form.errors.host }}</p>
                <p class="mt-3 text-xs text-[var(--text-tertiary)]">
                    Limite: {{ account.max_domains }} domínio(s). No domínio próprio funcionam Pix, boleto e cartão pelo checkout padrão; fluxos que redirecionam para PayPal, CajuPay SDK ou Lina continuam no domínio da plataforma.
                </p>

                <p v-if="!domains.length" class="mt-5 rounded-2xl border border-dashed border-[var(--border-strong)] px-4 py-6 text-center text-sm text-[var(--text-tertiary)]">Nenhum domínio cadastrado ainda.</p>

                <div v-for="d in domains" :key="d.id" class="mt-4 rounded-[22px] border border-[var(--border-strong)] p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex min-w-0 flex-wrap items-center gap-2">
                            <p class="break-all text-sm font-medium text-[var(--text-primary)]">{{ d.host }}</p>
                            <span class="pn-badge" :class="STATUS_BADGE[d.status] || 'pn-badge--neutral'">{{ STATUS[d.status] || d.status }}</span>
                        </div>
                        <div v-if="can_manage" class="flex gap-2">
                            <button type="button" class="pn-btn pn-btn--secondary pn-btn--sm !w-auto" @click="verify(d)">
                                <RefreshCw class="h-3.5 w-3.5" aria-hidden="true" /> Verificar
                            </button>
                            <button type="button" class="pn-btn pn-btn--ghost pn-btn--sm !w-auto" @click="remove(d)">
                                <Trash2 class="h-3.5 w-3.5" aria-hidden="true" /> Remover
                            </button>
                        </div>
                    </div>

                    <div class="mt-3 space-y-2 rounded-2xl bg-[var(--bg-muted)]/60 p-3">
                        <div class="grid gap-1 sm:grid-cols-[110px_1fr] sm:items-center">
                            <span class="text-[11px] text-[var(--text-tertiary)]">TXT (nome)</span>
                            <span class="flex min-w-0 items-start gap-2">
                                <code class="min-w-0 flex-1 break-all font-mono text-xs text-[var(--text-primary)]">{{ d.txt_name }}</code>
                                <button type="button" class="shrink-0 rounded-full p-1.5 text-[var(--text-secondary)] transition hover:bg-[var(--glass-bg-strong)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)]"
                                    :aria-label="copied === `n${d.id}` ? 'Copiado' : 'Copiar nome do registro TXT'" @click="copyField(d.txt_name, `n${d.id}`)">
                                    <Check v-if="copied === `n${d.id}`" class="h-3.5 w-3.5 text-[var(--lime-700)]" /><Copy v-else class="h-3.5 w-3.5" />
                                </button>
                            </span>
                        </div>
                        <div class="grid gap-1 sm:grid-cols-[110px_1fr] sm:items-center">
                            <span class="text-[11px] text-[var(--text-tertiary)]">TXT (valor)</span>
                            <span class="flex min-w-0 items-start gap-2">
                                <code class="min-w-0 flex-1 break-all font-mono text-xs text-[var(--text-primary)]">{{ d.txt_value }}</code>
                                <button type="button" class="shrink-0 rounded-full p-1.5 text-[var(--text-secondary)] transition hover:bg-[var(--glass-bg-strong)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)]"
                                    :aria-label="copied === `v${d.id}` ? 'Copiado' : 'Copiar valor do registro TXT'" @click="copyField(d.txt_value, `v${d.id}`)">
                                    <Check v-if="copied === `v${d.id}`" class="h-3.5 w-3.5 text-[var(--lime-700)]" /><Copy v-else class="h-3.5 w-3.5" />
                                </button>
                            </span>
                        </div>
                    </div>

                    <div class="mt-3 flex flex-wrap items-center gap-2 text-xs">
                        <span class="pn-badge pn-badge--nodot" :class="d.dns_ok ? 'pn-badge--success' : 'pn-badge--neutral'">
                            <component :is="d.dns_ok ? Check : Clock" class="h-3 w-3" aria-hidden="true" /> DNS: {{ d.dns_ok ? 'ok' : 'pendente' }}
                        </span>
                        <span class="pn-badge pn-badge--nodot" :class="d.https_ok ? 'pn-badge--success' : 'pn-badge--neutral'">
                            <component :is="d.https_ok ? Check : Clock" class="h-3 w-3" aria-hidden="true" /> HTTPS: {{ d.https_ok ? 'ok' : 'pendente' }}
                        </span>
                        <span v-if="d.last_checked_at" class="text-[var(--text-tertiary)]">Última verificação: {{ fmtDateTime(d.last_checked_at) }}</span>
                    </div>
                    <p v-if="d.last_error" class="mt-2 flex items-start gap-2 rounded-2xl bg-[var(--status-error-bg)] px-3 py-2 text-xs text-[var(--status-error-fg)]">
                        <TriangleAlert class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" /> {{ d.last_error }}
                    </p>
                </div>
            </PnPanel>
        </template>
    </div>
</template>
