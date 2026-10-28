<script setup>
import { computed, reactive, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import PnPageHeader from '@/components/paynext/PnPageHeader.vue';
import PnKpiCard from '@/components/paynext/PnKpiCard.vue';
import PnPanel from '@/components/paynext/PnPanel.vue';
import PnEmptyState from '@/components/paynext/PnEmptyState.vue';
import { Building2, CircleCheck, Globe, LoaderCircle, Palette, Pencil, Trash2 } from 'lucide-vue-next';

defineOptions({ layout: LayoutPlatform });

/** Brasa Enterprise (admin). White-label é só apresentação: não muda tenant financeiro nem credenciais. */
const props = defineProps({
    accounts: { type: Array, default: () => [] },
    branding_keys: { type: Array, default: () => [] },
});

const LABELS = { app_name: 'Nome exibido', theme_primary: 'Cor principal (#RRGGBB)', app_logo: 'Logo (https://)', app_logo_dark: 'Logo modo escuro (https://)', favicon_url: 'Favicon (https://)', login_hero_tagline: 'Frase da tela de login' };
const NAV = ['/vendas', '/crm', '/assinaturas', '/financeiro', '/relatorios', '/inteligencia', '/experimentos', '/biblioteca', '/recuperacao', '/integracoes', '/aplicacoes-api', '/desenvolvedores', '/usuarios/equipe'];

/* Só apresentação: contagens a partir da lista recebida. */
const activeCount = computed(() => props.accounts.filter((a) => a.status === 'active').length);
const domainCount = computed(() => props.accounts.reduce((n, a) => n + (a.domains?.length || 0), 0));
const FIELD = 'mt-1.5 h-10 w-full rounded-full border border-[var(--border-strong)] bg-[var(--glass-bg)] px-4 text-sm text-[var(--text-primary)] outline-none placeholder:text-[var(--text-tertiary)] focus:ring-4 focus:ring-[var(--focus-ring)]';
const LABEL = 'block text-xs text-[var(--text-secondary)]';

const enable = useForm({ seller: '', display_name: '' });
function doEnable() {
    enable.post('/plataforma/enterprise', { preserveScroll: true, onSuccess: () => enable.reset() });
}

const editing = ref(null);
const edit = reactive({ display_name: '', max_domains: 3, hidden_nav: [], branding: {} });
function open(a) {
    editing.value = a.id;
    Object.assign(edit, { display_name: a.display_name || '', max_domains: a.max_domains, hidden_nav: [...a.hidden_nav], branding: { ...a.branding } });
}
function save(a) {
    router.put(`/plataforma/enterprise/${a.id}`, { ...edit }, { preserveScroll: true, onSuccess: () => (editing.value = null) });
}
function toggle(a) {
    const suspend = a.status === 'active';
    const reason = window.prompt(suspend ? 'Motivo da suspensão (domínios param de responder):' : 'Motivo da reativação:');
    if (!reason) return;
    router.post(`/plataforma/enterprise/${a.id}/status`, { suspended: suspend, reason }, { preserveScroll: true });
}
function removeDomain(d) {
    if (window.confirm(`Remover ${d.host}?`)) router.delete(`/plataforma/enterprise/dominios/${d.id}`, { preserveScroll: true });
}
</script>

<template>
    <div class="mx-auto max-w-6xl space-y-6">
        <PnPageHeader eyebrow="Enterprise" title="White-label" :icon="Building2"
            description="Liberar marca e domínio próprio para um seller. Muda só a apresentação: o seller continua o mesmo tenant financeiro, com as mesmas adquirentes, carteira e responsabilidades. Toda alteração fica na auditoria." />

        <section class="grid grid-cols-1 gap-3 sm:grid-cols-3" aria-label="Resumo do white-label">
            <PnKpiCard label="Contas white-label" :value="accounts.length" :icon="Building2" :index="0" />
            <PnKpiCard label="Ativas" :value="activeCount" :icon="CircleCheck" :caption="`${accounts.length - activeCount} suspensa(s)`" :index="1" />
            <PnKpiCard label="Domínios próprios" :value="domainCount" :icon="Globe" :index="2" />
        </section>

        <PnPanel title="Ativar para um seller" sub="Informe o e-mail ou o ID da conta. O nome comercial pode ficar para depois.">
            <div class="flex flex-wrap items-end gap-3">
                <label class="w-full text-sm sm:w-72">
                    <span :class="LABEL">Seller</span>
                    <input v-model="enable.seller" placeholder="E-mail ou ID do seller" :class="FIELD" />
                </label>
                <label class="w-full text-sm sm:w-64">
                    <span :class="LABEL">Nome comercial</span>
                    <input v-model="enable.display_name" placeholder="Nome comercial (opcional)" :class="FIELD" />
                </label>
                <button type="button" class="pn-btn pn-btn--primary pn-btn--sm !w-auto" :disabled="enable.processing || !enable.seller" @click="doEnable">
                    <LoaderCircle v-if="enable.processing" class="h-4 w-4 animate-spin" aria-hidden="true" /> Ativar
                </button>
            </div>
            <p v-for="(e, k) in enable.errors" :key="k" class="mt-2 text-sm text-[var(--status-error-fg)]" role="alert">{{ e }}</p>
        </PnPanel>

        <PnPanel v-for="(a, ai) in accounts" :key="a.id" :index="ai + 1">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="text-base font-medium text-[var(--text-primary)]">{{ a.display_name || a.seller?.name }}</p>
                        <span :class="a.status === 'active' ? 'pn-badge pn-badge--success' : 'pn-badge pn-badge--warning'">{{ a.status === 'active' ? 'ativo' : 'suspenso' }}</span>
                    </div>
                    <p class="mt-1 text-xs text-[var(--text-tertiary)]">{{ a.seller?.email }} · conta #{{ a.tenant_id }} · até {{ a.max_domains }} domínio(s)</p>
                    <p v-if="a.suspension_reason" class="mt-2 rounded-2xl bg-[var(--status-warning-bg)] px-3 py-2 text-xs text-[var(--status-warning-fg)]">Motivo: {{ a.suspension_reason }}</p>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="pn-btn pn-btn--secondary pn-btn--sm !w-auto" :aria-expanded="editing === a.id" @click="open(a)"><Pencil class="h-3.5 w-3.5" aria-hidden="true" /> Editar</button>
                    <button type="button" class="pn-btn pn-btn--ghost pn-btn--sm !w-auto" @click="toggle(a)">{{ a.status === 'active' ? 'Suspender' : 'Reativar' }}</button>
                </div>
            </div>

            <div class="mt-4">
                <p class="mb-2 flex items-center gap-1.5 text-xs text-[var(--text-secondary)]"><Globe class="h-3.5 w-3.5" aria-hidden="true" /> Domínios</p>
                <ul v-if="a.domains?.length" class="divide-y divide-[var(--border-strong)] rounded-2xl border border-[var(--border-strong)] text-sm">
                    <li v-for="d in a.domains" :key="d.id" class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2">
                        <span class="min-w-0 break-all font-mono text-xs text-[var(--text-primary)]">{{ d.host }}</span>
                        <span :class="d.last_error ? 'pn-badge pn-badge--error pn-badge--nodot' : 'pn-badge pn-badge--neutral pn-badge--nodot'">{{ d.status }}</span>
                        <span v-if="d.last_error" class="min-w-0 break-words text-xs text-[var(--status-error-fg)]">{{ d.last_error }}</span>
                        <button type="button" class="ml-auto inline-flex h-8 items-center gap-1 rounded-full px-2.5 text-xs text-[var(--text-secondary)] transition hover:bg-[var(--bg-muted)] hover:text-[var(--text-primary)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)]" :aria-label="`Remover ${d.host}`" @click="removeDomain(d)">
                            <Trash2 class="h-3.5 w-3.5" aria-hidden="true" /> remover
                        </button>
                    </li>
                </ul>
                <p v-else class="text-xs text-[var(--text-tertiary)]">Nenhum domínio próprio ainda.</p>
            </div>

            <div v-if="editing === a.id" class="mt-5 space-y-5 rounded-[22px] border border-[var(--border-strong)] bg-[var(--bg-muted)]/50 p-4 sm:p-5">
                <p class="flex items-center gap-2 text-sm font-medium text-[var(--text-primary)]"><Palette class="h-4 w-4" aria-hidden="true" /> Marca e limites</p>
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="text-sm"><span :class="LABEL">Nome comercial</span><input v-model="edit.display_name" maxlength="120" :class="FIELD" /></label>
                    <label class="text-sm"><span :class="LABEL">Limite de domínios</span><input v-model.number="edit.max_domains" type="number" min="0" max="20" :class="FIELD" /></label>
                    <label v-for="k in branding_keys" :key="k" class="text-sm"><span :class="LABEL">{{ LABELS[k] || k }}</span><input v-model="edit.branding[k]" maxlength="500" :class="FIELD" /></label>
                </div>
                <fieldset>
                    <legend class="text-sm font-medium text-[var(--text-primary)]">Esconder do menu do seller</legend>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <label v-for="n in NAV" :key="n" class="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-full border border-[var(--border-strong)] bg-[var(--glass-bg)] px-3 font-mono text-xs text-[var(--text-secondary)] has-[:checked]:border-[var(--lime-500)] has-[:checked]:bg-[var(--lime-100)] has-[:checked]:text-[var(--ink-900)]">
                            <input v-model="edit.hidden_nav" type="checkbox" :value="n" class="h-3.5 w-3.5 accent-[var(--lime-500)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)]" />{{ n }}
                        </label>
                    </div>
                    <p class="mt-2 text-xs text-[var(--text-tertiary)]">Só esconde o item do menu; as permissões continuam as mesmas.</p>
                </fieldset>
                <div class="flex flex-wrap gap-2">
                    <button type="button" class="pn-btn pn-btn--primary pn-btn--sm !w-auto" @click="save(a)">Salvar</button>
                    <button type="button" class="pn-btn pn-btn--ghost pn-btn--sm !w-auto" @click="editing = null">Cancelar</button>
                </div>
            </div>
        </PnPanel>
        <PnEmptyState v-if="!accounts.length" title="Nenhuma conta white-label ainda" description="Use o formulário acima para liberar marca e domínio próprio para um seller." :icon="Building2" />
    </div>
</template>
