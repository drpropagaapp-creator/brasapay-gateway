<script setup>
import { reactive, ref } from 'vue';
import { router, Link, useForm } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import PnPageHeader from '@/components/paynext/PnPageHeader.vue';
import PnPanel from '@/components/paynext/PnPanel.vue';
import PnEmptyState from '@/components/paynext/PnEmptyState.vue';
import {
    LibraryBig, Star, Download, BadgeCheck, Search, Upload, Send, ShieldCheck, Gift, Eye, Check, X,
    ShoppingCart, LayoutTemplate, Funnel, Package, Tag, Boxes, Mail, ArrowUpRight,
} from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

/** Brasa Marketplace — catálogo de templates gratuitos, revisados pela plataforma. */
const props = defineProps({
    filters: { type: Object, required: true },
    categories: { type: Object, required: true },
    templates: { type: Array, default: () => [] },
    mine: { type: Array, default: () => [] },
    products: { type: Array, default: () => [] },
    can_publish: { type: Boolean, default: false },
});
const productCategories = Object.fromEntries(Object.entries(props.categories).filter(([k]) => k !== 'recovery'));

const f = reactive({ ...props.filters });
function apply() {
    router.get('/biblioteca', { q: f.q || undefined, category: f.category || undefined, sort: f.sort }, { preserveState: true, preserveScroll: true });
}

const STATUS = { pending_review: 'Em revisão', published: 'Publicado', rejected: 'Recusado', unpublished: 'Despublicado' };
const showSubmit = ref(false);
const form = useForm({ kind: 'product_package', template_id: null, product_id: '', title: '', category: 'kit', tagline: '', description: '', changelog: '', confirm_public: false });
function newVersion(t) {
    form.reset();
    form.kind = t.kind;
    form.template_id = t.id;
    showSubmit.value = true;
}
function submit() {
    form.post('/biblioteca/enviar', { preserveScroll: true, onSuccess: () => { form.reset(); showSubmit.value = false; } });
}

/* Apresentação */
const num = (v) => new Intl.NumberFormat('pt-BR').format(Number(v) || 0);
const STATUS_BADGE = { pending_review: 'pn-badge--warning', published: 'pn-badge--success', rejected: 'pn-badge--error', unpublished: 'pn-badge--neutral' };
const CATEGORY_ICON = { checkout: ShoppingCart, landing: LayoutTemplate, funnel: Funnel, product: Package, offers: Tag, kit: Boxes, recovery: Mail };
const CATEGORY_TONE = {
    checkout: 'bg-[var(--sky-100)] text-[var(--sky-600)]',
    landing: 'bg-[var(--lime-100)] text-[var(--lime-700)]',
    funnel: 'bg-[var(--sky-200)] text-[var(--ink-900)]',
    product: 'bg-[var(--ink-100)] text-[var(--ink-700)]',
    offers: 'bg-[var(--lime-200)] text-[var(--ink-900)]',
    kit: 'bg-[var(--ink-900)] text-[var(--lime-300)]',
    recovery: 'bg-[var(--sky-100)] text-[var(--ink-800)]',
};
const fieldCls = 'h-11 w-full rounded-full border border-[var(--border-strong)] bg-[var(--glass-bg)] px-4 text-sm text-[var(--text-primary)] outline-none transition focus:ring-4 focus:ring-[var(--focus-ring)]';
const areaCls = 'w-full rounded-[20px] border border-[var(--border-strong)] bg-[var(--glass-bg)] px-4 py-3 text-sm text-[var(--text-primary)] outline-none transition focus:ring-4 focus:ring-[var(--focus-ring)]';

function pickCategory(key) {
    f.category = key;
    apply();
}
function clearFilters() {
    f.q = '';
    f.category = null;
    apply();
}
function initials(name) {
    const parts = String(name || '?').trim().split(/\s+/);
    return ((parts[0]?.[0] || '?') + (parts[1]?.[0] || '')).toUpperCase();
}
</script>

<template>
    <div class="space-y-6">
        <PnPageHeader
            eyebrow="Marketplace"
            title="Biblioteca de templates"
            :icon="LibraryBig"
            description="Kits, checkouts, landing pages e textos de recuperação prontos. Todos gratuitos e revisados pela equipe."
        >
            <template v-if="can_publish" #actions>
                <button type="button" class="pn-btn pn-btn--secondary pn-btn--sm !w-auto" :aria-expanded="showSubmit" @click="showSubmit = !showSubmit; form.reset()">
                    <Upload class="h-3.5 w-3.5" aria-hidden="true" /> Publicar um template
                </button>
            </template>
        </PnPageHeader>

        <!-- Garantias -->
        <section class="pn-glass grid gap-4 p-5 sm:grid-cols-3" aria-label="Como funciona">
            <div class="flex items-start gap-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-[var(--lime-300)] text-[var(--ink-900)]"><Gift class="h-4 w-4" aria-hidden="true" /></span>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-[var(--text-primary)]">Gratuitos e revisados</p>
                    <p class="text-xs text-[var(--text-tertiary)]">Todo template passa pela equipe antes de entrar no catálogo.</p>
                </div>
            </div>
            <div class="flex items-start gap-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-[var(--border-strong)] text-[var(--text-secondary)]"><Eye class="h-4 w-4" aria-hidden="true" /></span>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-[var(--text-primary)]">Você confere antes</p>
                    <p class="text-xs text-[var(--text-tertiary)]">Instalar cria uma cópia na sua conta, que você confere antes de confirmar.</p>
                </div>
            </div>
            <div class="flex items-start gap-3">
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full border border-[var(--border-strong)] text-[var(--text-secondary)]"><ShieldCheck class="h-4 w-4" aria-hidden="true" /></span>
                <div class="min-w-0">
                    <p class="text-sm font-medium text-[var(--text-primary)]">Sem dados sensíveis</p>
                    <p class="text-xs text-[var(--text-tertiary)]">Nenhum template traz credenciais, cupons, integrações, coprodutores ou dados de compradores.</p>
                </div>
            </div>
        </section>

        <!-- Publicar / nova versão -->
        <PnPanel v-if="showSubmit" :title="form.template_id ? 'Nova versão' : 'Publicar um template'" sub="Depois de enviado, o template passa pela revisão da equipe.">
            <div class="grid gap-4 md:grid-cols-2">
                <div v-if="!form.template_id" class="flex flex-col gap-1.5">
                    <label for="tpl-kind" class="text-xs text-[var(--text-secondary)]">Origem</label>
                    <select id="tpl-kind" v-model="form.kind" :class="fieldCls">
                        <option value="product_package">A partir de um produto meu</option>
                        <option value="recovery">Meus textos de recuperação de vendas</option>
                    </select>
                </div>
                <div v-if="form.kind === 'product_package'" class="flex flex-col gap-1.5">
                    <label for="tpl-product" class="text-xs text-[var(--text-secondary)]">Produto</label>
                    <select id="tpl-product" v-model="form.product_id" :class="fieldCls">
                        <option value="">Produto…</option>
                        <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                </div>
                <template v-if="!form.template_id">
                    <div class="flex flex-col gap-1.5">
                        <label for="tpl-title" class="text-xs text-[var(--text-secondary)]">Título</label>
                        <input id="tpl-title" v-model="form.title" maxlength="120" placeholder="Título" :class="fieldCls" />
                    </div>
                    <div v-if="form.kind === 'product_package'" class="flex flex-col gap-1.5">
                        <label for="tpl-category" class="text-xs text-[var(--text-secondary)]">Categoria</label>
                        <select id="tpl-category" v-model="form.category" :class="fieldCls">
                            <option v-for="(label, key) in productCategories" :key="key" :value="key">{{ label }}</option>
                        </select>
                    </div>
                    <div class="flex flex-col gap-1.5 md:col-span-2">
                        <label for="tpl-tagline" class="text-xs text-[var(--text-secondary)]">Resumo</label>
                        <input id="tpl-tagline" v-model="form.tagline" maxlength="200" placeholder="Resumo em uma frase" :class="fieldCls" />
                    </div>
                    <div class="flex flex-col gap-1.5 md:col-span-2">
                        <label for="tpl-description" class="text-xs text-[var(--text-secondary)]">Descrição</label>
                        <textarea id="tpl-description" v-model="form.description" rows="3" maxlength="5000" placeholder="Descrição: para quem é, o que vem no template" :class="areaCls" />
                    </div>
                </template>
                <div class="flex flex-col gap-1.5 md:col-span-2">
                    <label for="tpl-changelog" class="text-xs text-[var(--text-secondary)]">Novidades da versão</label>
                    <input id="tpl-changelog" v-model="form.changelog" maxlength="1000" placeholder="O que mudou nesta versão (opcional)" :class="fieldCls" />
                </div>
            </div>

            <div v-if="form.kind === 'product_package'" class="mt-4 grid gap-3 sm:grid-cols-2">
                <div class="rounded-2xl bg-[var(--bg-muted)]/60 p-4">
                    <p class="flex items-center gap-1.5 text-xs font-medium text-[var(--text-primary)]"><Check class="h-3.5 w-3.5 text-[var(--lime-700)]" aria-hidden="true" /> Vão no template</p>
                    <p class="mt-1 text-xs text-[var(--text-secondary)]">Dados do produto, ofertas, planos, order bumps, checkout e landing.</p>
                </div>
                <div class="rounded-2xl border border-dashed border-[var(--border-strong)] p-4">
                    <p class="flex items-center gap-1.5 text-xs font-medium text-[var(--text-primary)]"><X class="h-3.5 w-3.5 text-[var(--status-error-fg)]" aria-hidden="true" /> Não vão</p>
                    <p class="mt-1 text-xs text-[var(--text-secondary)]">Aulas e comunidade da área de membros, coprodutores, cupons, integrações, credenciais e compradores.</p>
                </div>
            </div>

            <label class="mt-4 flex cursor-pointer items-start gap-3 rounded-2xl border border-[var(--border-strong)] p-4 text-sm text-[var(--text-primary)]">
                <input v-model="form.confirm_public" type="checkbox" class="mt-0.5 h-4 w-4 shrink-0 accent-[var(--lime-500)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)]" />
                <span>Entendo que, depois de aprovado, este conteúdo fica disponível gratuitamente para todos os sellers da plataforma.</span>
            </label>
            <div v-if="Object.keys(form.errors).length" class="mt-3 space-y-1 rounded-2xl bg-[var(--status-error-bg)] px-4 py-3" role="alert">
                <p v-for="(e, k) in form.errors" :key="k" class="text-sm text-[var(--status-error-fg)]">{{ e }}</p>
            </div>
            <div class="mt-4 flex justify-end">
                <button type="button" class="pn-btn pn-btn--primary pn-btn--sm !w-auto"
                    :disabled="form.processing || !form.confirm_public || (form.kind === 'product_package' && !form.product_id) || (!form.template_id && !form.title)" @click="submit">
                    <Send class="h-3.5 w-3.5" aria-hidden="true" /> Enviar para revisão
                </button>
            </div>
        </PnPanel>

        <!-- Meus templates -->
        <PnPanel v-if="mine.length" title="Meus templates" :sub="`${mine.length} enviado(s) por você`">
            <ul class="divide-y divide-[var(--border-strong)]">
                <li v-for="t in mine" :key="t.id" class="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
                    <div class="flex min-w-0 flex-1 items-start gap-3">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-2xl" :class="CATEGORY_TONE[t.category] || CATEGORY_TONE.product">
                            <component :is="CATEGORY_ICON[t.category] || Package" class="h-4 w-4" aria-hidden="true" />
                        </span>
                        <div class="min-w-0">
                            <Link :href="`/biblioteca/${t.slug}`" class="text-sm font-medium text-[var(--text-primary)] hover:underline focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)] rounded">{{ t.title }}</Link>
                            <div class="mt-1 flex flex-wrap items-center gap-1.5">
                                <span class="pn-badge" :class="STATUS_BADGE[t.status] || 'pn-badge--neutral'">{{ STATUS[t.status] }}</span>
                                <span v-if="t.pending_version" class="pn-badge pn-badge--info">v{{ t.pending_version }} em revisão</span>
                                <span class="inline-flex items-center gap-1 text-xs text-[var(--text-tertiary)]"><Download class="h-3 w-3" aria-hidden="true" /> {{ num(t.installs) }} instalações</span>
                            </div>
                            <p v-if="t.rejection_reason" class="mt-1.5 text-xs text-[var(--status-error-fg)]">Motivo: {{ t.rejection_reason }}</p>
                        </div>
                    </div>
                    <button v-if="!t.pending_version" type="button" class="pn-btn pn-btn--ghost pn-btn--sm !w-auto" @click="newVersion(t)">
                        <Upload class="h-3.5 w-3.5" aria-hidden="true" /> Enviar nova versão
                    </button>
                </li>
            </ul>
        </PnPanel>

        <!-- Filtros -->
        <div class="space-y-3">
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative w-full sm:w-72">
                    <Search class="pointer-events-none absolute left-4 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--text-tertiary)]" aria-hidden="true" />
                    <input v-model="f.q" type="search" placeholder="Buscar templates" aria-label="Buscar templates"
                        class="h-10 w-full rounded-full border border-[var(--border-strong)] bg-[var(--glass-bg)] pl-10 pr-4 text-sm text-[var(--text-primary)] outline-none focus:ring-4 focus:ring-[var(--focus-ring)]" @keyup.enter="apply" />
                </div>
                <select v-model="f.category" aria-label="Categoria"
                    class="h-10 min-w-0 flex-1 rounded-full border border-[var(--border-strong)] bg-[var(--glass-bg)] px-4 text-sm text-[var(--text-primary)] outline-none focus:ring-4 focus:ring-[var(--focus-ring)] sm:hidden" @change="apply">
                    <option :value="null">Todas as categorias</option>
                    <option v-for="(label, key) in categories" :key="key" :value="key">{{ label }}</option>
                </select>
                <select v-model="f.sort" aria-label="Ordenar"
                    class="h-10 min-w-0 flex-1 rounded-full border border-[var(--border-strong)] bg-[var(--glass-bg)] px-4 text-sm text-[var(--text-primary)] outline-none focus:ring-4 focus:ring-[var(--focus-ring)] sm:ml-auto sm:flex-none" @change="apply">
                    <option value="popular">Mais instalados</option>
                    <option value="rating">Melhor avaliados</option>
                    <option value="new">Mais novos</option>
                </select>
            </div>
            <div class="hidden flex-wrap gap-2 sm:flex" role="group" aria-label="Categorias">
                <button type="button" class="pn-chip h-9 text-[12.5px] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)]" :class="!f.category ? 'pn-chip--active' : ''" :aria-pressed="!f.category" @click="pickCategory(null)">
                    Todas
                </button>
                <button v-for="(label, key) in categories" :key="key" type="button"
                    class="pn-chip h-9 text-[12.5px] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)]" :class="f.category === key ? 'pn-chip--active' : ''" :aria-pressed="f.category === key" @click="pickCategory(key)">
                    <component :is="CATEGORY_ICON[key] || Package" class="h-3.5 w-3.5" aria-hidden="true" /> {{ label }}
                </button>
            </div>
        </div>

        <!-- Catálogo -->
        <p v-if="templates.length" class="text-xs text-[var(--text-tertiary)]">{{ num(templates.length) }} template(s)</p>
        <PnEmptyState v-if="!templates.length" :icon="LibraryBig" title="Nenhum template encontrado." description="Tente outra busca ou outra categoria.">
            <button v-if="f.q || f.category" type="button" class="pn-btn pn-btn--secondary pn-btn--sm !w-auto" @click="clearFilters">Limpar filtros</button>
        </PnEmptyState>
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <Link v-for="t in templates" :key="t.id" :href="`/biblioteca/${t.slug}`"
                class="pn-glass group flex flex-col overflow-hidden transition hover:-translate-y-0.5 hover:shadow-md focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)] motion-reduce:transition-none motion-reduce:hover:translate-y-0">
                <div class="relative flex h-28 items-center justify-center" :class="CATEGORY_TONE[t.category] || CATEGORY_TONE.product">
                    <component :is="CATEGORY_ICON[t.category] || Package" class="h-10 w-10 opacity-80" aria-hidden="true" />
                    <span class="absolute left-3 top-3 rounded-full bg-[var(--glass-bg-strong)] px-2.5 py-0.5 text-[11px] font-medium text-[var(--text-primary)]">{{ t.category_label }}</span>
                    <span v-if="t.is_official" class="pn-badge pn-badge--brand absolute right-3 top-3">Oficial</span>
                </div>
                <div class="flex flex-1 flex-col p-5">
                    <div class="flex items-start justify-between gap-2">
                        <p class="text-base font-medium leading-snug text-[var(--text-primary)]">{{ t.title }}</p>
                        <ArrowUpRight class="h-4 w-4 shrink-0 text-[var(--text-tertiary)] opacity-0 transition group-hover:opacity-100" aria-hidden="true" />
                    </div>
                    <p class="mt-1 line-clamp-2 flex-1 text-sm text-[var(--text-secondary)]">{{ t.tagline }}</p>
                    <div class="mt-4 flex items-center gap-2">
                        <span class="pn-avatar pn-avatar--sm" :class="t.is_official ? '!bg-[var(--lime-300)]' : ''" aria-hidden="true">{{ initials(t.author) }}</span>
                        <span class="flex min-w-0 items-center gap-1 text-xs text-[var(--text-secondary)]">
                            <span class="truncate">{{ t.author }}</span>
                            <BadgeCheck v-if="t.is_official" class="h-3.5 w-3.5 shrink-0 text-[var(--lime-700)]" aria-label="Oficial" />
                        </span>
                    </div>
                    <div class="mt-4 flex flex-wrap items-center gap-x-3 gap-y-1 border-t border-[var(--border-strong)] pt-3 text-xs text-[var(--text-tertiary)]">
                        <span class="flex items-center gap-1 tabular-nums"><Download class="h-3.5 w-3.5" aria-hidden="true" />{{ num(t.installs) }}</span>
                        <span v-if="t.rating" class="flex items-center gap-1 tabular-nums"><Star class="h-3.5 w-3.5 fill-amber-400 text-amber-400" aria-hidden="true" />{{ t.rating }} ({{ t.rating_count }})</span>
                        <span v-else>Sem avaliações</span>
                        <span class="pn-badge pn-badge--success pn-badge--nodot ml-auto">Grátis</span>
                    </div>
                </div>
            </Link>
        </div>
    </div>
</template>
