<script setup>
import { computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import PnPageHeader from '@/components/paynext/PnPageHeader.vue';
import PnKpiCard from '@/components/paynext/PnKpiCard.vue';
import PnPanel from '@/components/paynext/PnPanel.vue';
import { Check, Download, Inbox, Library, LoaderCircle, MessageSquare, Star, TriangleAlert, Upload, X } from 'lucide-vue-next';

defineOptions({ layout: LayoutPlatform });

/** Brasa Marketplace (admin): nada entra no catálogo sem passar por aqui. */
const props = defineProps({
    categories: { type: Object, required: true },
    max_mb: { type: Number, default: 200 },
    pending: { type: Array, default: () => [] },
    templates: { type: Array, default: () => [] },
    reviews: { type: Array, default: () => [] },
});
const productCategories = Object.fromEntries(Object.entries(props.categories).filter(([k]) => k !== 'recovery'));

/* Só apresentação: contagem do catálogo a partir da lista recebida. */
const publishedCount = computed(() => props.templates.filter((t) => t.status === 'published').length);
const FIELD = 'mt-1.5 h-10 w-full rounded-full border border-[var(--border-strong)] bg-[var(--glass-bg)] px-4 text-sm text-[var(--text-primary)] outline-none placeholder:text-[var(--text-tertiary)] focus:ring-4 focus:ring-[var(--focus-ring)]';
const LABEL = 'block text-xs text-[var(--text-secondary)]';
const THEAD = 'text-left text-[11px] uppercase tracking-[0.06em] text-[var(--text-tertiary)]';
const TH = 'whitespace-nowrap px-3 py-2.5 font-normal first:pl-0 last:pr-0';
const TD = 'px-3 py-2.5 first:pl-0 last:pr-0';

function decide(v, decision) {
    let reason = null;
    if (decision === 'reject') {
        reason = window.prompt('Motivo da recusa (o autor verá):');
        if (!reason) return;
    } else if (!window.confirm(`Aprovar "${v.template?.title}" v${v.version} e publicar no catálogo?`)) {
        return;
    }
    router.post(`/plataforma/biblioteca/versoes/${v.id}/revisar`, { decision, reason }, { preserveScroll: true });
}
function togglePublished(t) {
    const reason = window.prompt(t.status === 'published' ? 'Motivo para tirar do catálogo:' : 'Motivo para republicar:');
    if (!reason) return;
    router.post(`/plataforma/biblioteca/templates/${t.id}/publicacao`, { published: t.status !== 'published', reason }, { preserveScroll: true });
}
function moderate(r, decision) {
    router.post(`/plataforma/biblioteca/avaliacoes/${r.id}`, { decision }, { preserveScroll: true });
}
const official = useForm({ package: null, title: '', category: 'kit', tagline: '', description: '' });
function sendOfficial() {
    official.post('/plataforma/biblioteca/oficial', { forceFormData: true, preserveScroll: true, onSuccess: () => official.reset() });
}
</script>

<template>
    <div class="mx-auto max-w-6xl space-y-6">
        <PnPageHeader eyebrow="Marketplace" title="Biblioteca de templates" :icon="Library"
            description="Revise antes de publicar: o pacote é instalado pelo importador oficial (sem credenciais, cupons, integrações, coprodutores ou compradores), mas textos, imagens e landing ficam públicos para todos os sellers." />

        <section class="grid grid-cols-1 gap-3 sm:grid-cols-3" aria-label="Resumo da biblioteca">
            <PnKpiCard label="Aguardando revisão" :value="pending.length" :icon="Inbox" :index="0" />
            <PnKpiCard label="Avaliações para moderar" :value="reviews.length" :icon="MessageSquare" :index="1" />
            <PnKpiCard label="Publicados no catálogo" :value="publishedCount" :icon="Library" :caption="`${templates.length} template(s) no total`" :index="2" />
        </section>

        <!-- Aguardando revisão -->
        <PnPanel :title="`Aguardando revisão (${pending.length})`" sub="Aprovar publica a versão no catálogo; recusar envia o motivo ao autor.">
            <p v-if="!pending.length" class="text-sm text-[var(--text-tertiary)]">Nada pendente.</p>
            <div v-else class="space-y-3">
                <article v-for="v in pending" :key="v.id" class="rounded-[22px] border border-[var(--border-strong)] bg-[var(--glass-bg-strong)] p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="font-medium text-[var(--text-primary)]">{{ v.template?.title }}</p>
                                <span class="pn-badge pn-badge--neutral pn-badge--nodot">v{{ v.version }}</span>
                                <span class="pn-badge pn-badge--info pn-badge--nodot">{{ v.template?.kind === 'recovery' ? 'textos de recuperação' : 'pacote de produto' }}</span>
                            </div>
                            <p class="mt-1 text-xs text-[var(--text-tertiary)]">{{ v.template?.category }} · Autor: {{ v.template?.author }} (conta #{{ v.template?.author_tenant_id ?? 'oficial' }})</p>
                            <p v-if="v.template?.tagline" class="mt-1.5 text-sm text-[var(--text-secondary)]">{{ v.template.tagline }}</p>
                            <p v-if="v.changelog" class="mt-1 text-xs text-[var(--text-tertiary)]">Mudanças: {{ v.changelog }}</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button type="button" class="pn-btn pn-btn--primary pn-btn--sm !w-auto" @click="decide(v, 'approve')"><Check class="h-3.5 w-3.5" aria-hidden="true" /> Aprovar</button>
                            <button type="button" class="pn-btn pn-btn--secondary pn-btn--sm !w-auto" @click="decide(v, 'reject')"><X class="h-3.5 w-3.5" aria-hidden="true" /> Recusar</button>
                        </div>
                    </div>
                    <p v-if="v.template?.description" class="mt-3 whitespace-pre-line text-sm text-[var(--text-secondary)]">{{ v.template.description }}</p>
                    <ul v-if="v.summary?.products" class="mt-3 space-y-1 text-xs text-[var(--text-secondary)]">
                        <li v-for="p in v.summary.products" :key="p.key" class="rounded-xl bg-[var(--bg-muted)]/60 px-3 py-1.5">{{ p.name }} · {{ p.offers }} ofertas · {{ p.plans }} planos · {{ p.order_bumps }} bumps · {{ v.summary.assets }} arquivos</li>
                    </ul>
                    <a v-if="v.has_package" :href="`/plataforma/biblioteca/versoes/${v.id}/pacote`" class="mt-3 inline-flex items-center gap-1.5 rounded-full text-xs text-[var(--text-secondary)] underline-offset-2 hover:text-[var(--text-primary)] hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--focus-ring)]">
                        <Download class="h-3.5 w-3.5" aria-hidden="true" /> Baixar pacote para conferir (importe numa conta de teste)
                    </a>
                    <template v-if="v.content?.events">
                        <p class="mt-3 text-xs text-[var(--text-tertiary)]">{{ v.summary?.steps }} etapas de e-mail. Textos completos:</p>
                        <template v-for="(cfg, ev) in v.content.events" :key="ev">
                            <div v-for="(s, i) in cfg.steps" :key="ev + i" class="mt-2 rounded-2xl bg-[var(--bg-muted)]/60 p-3 text-xs text-[var(--text-secondary)]">
                                <p class="text-[var(--text-tertiary)]">{{ ev }} · etapa {{ i + 1 }} · {{ s.delay_minutes }} min</p>
                                <p class="mt-0.5 font-medium text-[var(--text-primary)]">{{ s.subject }}</p>
                                <p class="mt-1 whitespace-pre-line">{{ s.body }}</p>
                            </div>
                        </template>
                    </template>
                    <p v-if="v.links?.length" class="mt-3 flex items-start gap-2 break-all rounded-2xl border border-red-300/70 bg-red-50/80 p-3 text-xs text-red-800 dark:border-red-900/70 dark:bg-red-950/30 dark:text-red-200">
                        <TriangleAlert class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" /><span>Links encontrados: {{ v.links.join(' · ') }}</span>
                    </p>
                </article>
            </div>
        </PnPanel>

        <!-- Template oficial -->
        <PnPanel title="Publicar template oficial" :sub="`Envie um pacote exportado (.brasapay.zip) de um produto modelo. Até ${max_mb} MB.`">
            <div class="grid gap-4 md:grid-cols-2">
                <label class="text-sm">
                    <span :class="LABEL">Pacote (.zip)</span>
                    <input type="file" accept=".zip"
                        class="mt-1.5 block w-full text-sm text-[var(--text-secondary)] file:mr-3 file:h-10 file:cursor-pointer file:rounded-full file:border file:border-solid file:border-[var(--border-strong)] file:bg-[var(--glass-bg)] file:px-4 file:text-sm file:text-[var(--text-primary)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)] rounded-full"
                        @change="official.package = $event.target.files[0]" />
                </label>
                <label class="text-sm"><span :class="LABEL">Título</span><input v-model="official.title" maxlength="120" placeholder="Título" :class="FIELD" /></label>
                <label class="text-sm">
                    <span :class="LABEL">Categoria</span>
                    <select v-model="official.category" :class="FIELD">
                        <option v-for="(label, key) in productCategories" :key="key" :value="key">{{ label }}</option>
                    </select>
                </label>
                <label class="text-sm"><span :class="LABEL">Resumo</span><input v-model="official.tagline" maxlength="200" placeholder="Resumo" :class="FIELD" /></label>
                <label class="text-sm md:col-span-2">
                    <span :class="LABEL">Descrição</span>
                    <textarea v-model="official.description" rows="2" maxlength="5000" placeholder="Descrição"
                        class="mt-1.5 w-full rounded-2xl border border-[var(--border-strong)] bg-[var(--glass-bg)] px-4 py-2.5 text-sm text-[var(--text-primary)] outline-none placeholder:text-[var(--text-tertiary)] focus:ring-4 focus:ring-[var(--focus-ring)]" />
                </label>
            </div>
            <p v-for="(e, k) in official.errors" :key="k" class="mt-2 text-sm text-[var(--status-error-fg)]" role="alert">{{ e }}</p>
            <button type="button" class="pn-btn pn-btn--primary pn-btn--sm !w-auto mt-4" :disabled="official.processing || !official.package || !official.title" @click="sendOfficial">
                <LoaderCircle v-if="official.processing" class="h-4 w-4 animate-spin" aria-hidden="true" /><Upload v-else class="h-4 w-4" aria-hidden="true" /> Publicar
            </button>
        </PnPanel>

        <!-- Avaliações -->
        <PnPanel :title="`Avaliações para moderar (${reviews.length})`" sub="Publicar deixa a avaliação visível no catálogo.">
            <ul class="divide-y divide-[var(--border-strong)] text-sm">
                <li v-for="r in reviews" :key="r.id" class="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0">
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-2 text-[var(--text-primary)]">
                            <span class="inline-flex items-center gap-1 rounded-full bg-[var(--lime-100)] px-2 py-0.5 text-xs font-medium text-[var(--lime-700)]"><Star class="h-3 w-3" aria-hidden="true" />{{ r.rating }}/5</span>
                            <span class="font-medium">{{ r.template }}</span>
                        </p>
                        <p v-if="r.comment" class="mt-1 text-[var(--text-secondary)]">{{ r.comment }}</p>
                    </div>
                    <div class="flex gap-2">
                        <button type="button" class="pn-btn pn-btn--primary pn-btn--sm !w-auto" @click="moderate(r, 'approve')">Publicar</button>
                        <button type="button" class="pn-btn pn-btn--secondary pn-btn--sm !w-auto" @click="moderate(r, 'reject')">Recusar</button>
                    </div>
                </li>
                <li v-if="!reviews.length" class="py-2 text-[var(--text-tertiary)]">Nada pendente.</li>
            </ul>
        </PnPanel>

        <!-- Catálogo -->
        <PnPanel title="Catálogo" sub="Tirar do catálogo ou republicar pede um motivo.">
            <div class="relative overflow-x-auto">
                <table class="w-full min-w-[620px] text-sm">
                    <thead :class="THEAD"><tr><th :class="TH">Template</th><th :class="TH">Autor</th><th :class="TH">Instalações</th><th :class="TH">Nota</th><th :class="TH">Status</th><th :class="TH"><span class="sr-only">Ações</span></th></tr></thead>
                    <tbody class="text-[var(--text-primary)]">
                        <tr v-for="t in templates" :key="t.id" class="border-t border-[var(--border-strong)] transition-colors hover:bg-[var(--bg-muted)]/40">
                            <td :class="TD" class="font-medium">{{ t.title }}</td>
                            <td :class="TD"><span v-if="t.is_official" class="pn-badge pn-badge--brand pn-badge--nodot">Oficial</span><template v-else>{{ t.author }}</template></td>
                            <td :class="TD" class="tabular-nums">{{ t.installs }}</td><td :class="TD" class="tabular-nums">{{ t.rating ?? '—' }}</td>
                            <td :class="TD"><span :class="t.status === 'published' ? 'pn-badge pn-badge--success' : 'pn-badge pn-badge--neutral'">{{ t.status === 'published' ? 'publicado' : 'fora do catálogo' }}</span></td>
                            <td :class="TD" class="text-right"><button type="button" class="pn-btn pn-btn--ghost pn-btn--sm !w-auto" @click="togglePublished(t)">{{ t.status === 'published' ? 'Tirar do catálogo' : 'Republicar' }}</button></td>
                        </tr>
                        <tr v-if="!templates.length"><td colspan="6" class="py-6 text-center text-[var(--text-tertiary)]">Nenhum template no catálogo ainda.</td></tr>
                    </tbody>
                </table>
            </div>
        </PnPanel>
    </div>
</template>
