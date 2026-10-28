<script setup>
import { Link, router, useForm } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import PnPageHeader from '@/components/paynext/PnPageHeader.vue';
import PnPanel from '@/components/paynext/PnPanel.vue';
import {
    ArrowLeft, Star, BadgeCheck, Download, ShieldCheck, TriangleAlert, Info, History, MessageSquare, Mail, Clock,
    ShoppingCart, LayoutTemplate, Funnel, Package, Tag, Boxes, LibraryBig, ArrowUpRight,
} from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

const props = defineProps({
    template: { type: Object, required: true },
    summary: { type: Object, default: null },
    content: { type: Object, default: null },
    current_version: { type: Number, default: null },
    versions: { type: Array, default: () => [] },
    reviews: { type: Array, default: () => [] },
    my_installs: { type: Array, default: () => [] },
    update_available: { type: Boolean, default: false },
    can_install: { type: Boolean, default: false },
    can_review: { type: Boolean, default: false },
    my_review: { type: Object, default: null },
});

const fmt = (iso) => (iso ? new Date(iso).toLocaleDateString('pt-BR') : '');
const IMPORT_STATUS = { ready: 'aguardando sua confirmação', importing: 'importando', completed: 'concluída', failed: 'falhou', cancelled: 'cancelada', validating: 'validando', started: 'iniciada' };
const EVENTS = { cart_abandoned: 'Carrinho abandonado', pix_pending: 'Pix não pago', boleto_pending: 'Boleto não pago' };

function install() {
    const msg = props.template.kind === 'recovery'
        ? 'Substituir os textos das etapas de recuperação pelos deste template? A recuperação continua ligada ou desligada como está.'
        : 'Abrir a prévia da instalação? Nada é criado até você confirmar na próxima tela.';
    if (window.confirm(msg)) router.post(`/biblioteca/${props.template.slug}/instalar`);
}
const review = useForm({ rating: props.my_review?.rating ?? 5, comment: props.my_review?.comment ?? '' });
function sendReview() {
    review.post(`/biblioteca/${props.template.slug}/avaliar`, { preserveScroll: true });
}

/* Apresentação */
const num = (v) => new Intl.NumberFormat('pt-BR').format(Number(v) || 0);
const CATEGORY_ICON = { checkout: ShoppingCart, landing: LayoutTemplate, funnel: Funnel, product: Package, offers: Tag, kit: Boxes, recovery: Mail };
const IMPORT_BADGE = { ready: 'pn-badge--warning', importing: 'pn-badge--info', validating: 'pn-badge--info', started: 'pn-badge--info', completed: 'pn-badge--success', failed: 'pn-badge--error', cancelled: 'pn-badge--neutral' };
const STATUS_LABEL = { pending_review: 'em revisão', rejected: 'recusado', unpublished: 'despublicado' };
function initials(name) {
    const parts = String(name || '?').trim().split(/\s+/);
    return ((parts[0]?.[0] || '?') + (parts[1]?.[0] || '')).toUpperCase();
}
function eyebrow() {
    return props.current_version ? `${props.template.category_label} · versão ${props.current_version}` : props.template.category_label;
}
</script>

<template>
    <div class="space-y-6">
        <Link href="/biblioteca" class="inline-flex items-center gap-1.5 rounded-full text-sm text-[var(--text-secondary)] hover:text-[var(--text-primary)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)]">
            <ArrowLeft class="h-4 w-4" aria-hidden="true" /> Biblioteca
        </Link>

        <PnPageHeader :eyebrow="eyebrow()" :title="template.title" :description="template.tagline || ''" :icon="CATEGORY_ICON[template.category] || LibraryBig" />

        <!-- Autor e números -->
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2 text-sm text-[var(--text-secondary)]">
            <span class="flex items-center gap-2">
                <span class="pn-avatar pn-avatar--sm" :class="template.is_official ? '!bg-[var(--lime-300)]' : ''" aria-hidden="true">{{ initials(template.author) }}</span>
                <span class="text-[var(--text-primary)]">{{ template.author }}</span>
                <BadgeCheck v-if="template.is_official" class="h-4 w-4 text-[var(--lime-700)]" aria-label="Oficial" />
            </span>
            <span class="flex items-center gap-1 tabular-nums"><Download class="h-4 w-4 text-[var(--text-tertiary)]" aria-hidden="true" /> {{ num(template.installs) }} instalações</span>
            <span v-if="template.rating" class="flex items-center gap-1 tabular-nums"><Star class="h-4 w-4 fill-amber-400 text-amber-400" aria-hidden="true" /> {{ template.rating }} ({{ template.rating_count }})</span>
            <span class="pn-badge pn-badge--success pn-badge--nodot">Grátis</span>
        </div>

        <div class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
            <!-- Coluna lateral (aparece primeiro no celular) -->
            <aside class="space-y-4 lg:sticky lg:top-6 lg:order-2">
                <div class="pn-glass p-5">
                    <p class="text-[11px] uppercase tracking-[0.08em] text-[var(--text-tertiary)]">Preço</p>
                    <p class="mt-1 text-2xl font-medium tracking-tight text-[var(--text-primary)]">Grátis</p>
                    <button v-if="can_install" type="button" class="pn-btn pn-btn--primary mt-4 w-full" @click="install">
                        <Download class="h-4 w-4" aria-hidden="true" /> {{ my_installs.length ? 'Instalar de novo' : 'Instalar' }}
                    </button>
                    <p v-if="update_available" class="mt-3 flex items-start gap-2 rounded-2xl bg-[var(--status-warning-bg)] px-3 py-2 text-xs text-[var(--status-warning-fg)]">
                        <TriangleAlert class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" /> Há uma versão nova desde a sua última instalação.
                    </p>
                    <p v-if="template.status !== 'published'" class="mt-3 flex items-start gap-2 rounded-2xl bg-[var(--bg-muted)]/60 px-3 py-2 text-xs text-[var(--text-secondary)]">
                        <Info class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" /> Ainda não está no catálogo ({{ STATUS_LABEL[template.status] || template.status }}).
                    </p>
                    <p class="mt-3 text-xs text-[var(--text-tertiary)]">
                        {{ template.kind === 'recovery' ? 'Os textos substituem as etapas atuais da sua recuperação de vendas.' : 'Nada é criado até você confirmar na tela de prévia.' }}
                    </p>

                    <dl class="mt-4 grid grid-cols-2 gap-2">
                        <div class="rounded-2xl bg-[var(--bg-muted)]/60 p-3">
                            <dt class="text-[11px] text-[var(--text-tertiary)]">Categoria</dt>
                            <dd class="mt-0.5 text-sm font-medium text-[var(--text-primary)]">{{ template.category_label }}</dd>
                        </div>
                        <div class="rounded-2xl bg-[var(--bg-muted)]/60 p-3">
                            <dt class="text-[11px] text-[var(--text-tertiary)]">Versão</dt>
                            <dd class="mt-0.5 text-sm font-medium tabular-nums text-[var(--text-primary)]">{{ current_version ? `v${current_version}` : '—' }}</dd>
                        </div>
                        <div class="rounded-2xl bg-[var(--bg-muted)]/60 p-3">
                            <dt class="text-[11px] text-[var(--text-tertiary)]">Instalações</dt>
                            <dd class="mt-0.5 text-sm font-medium tabular-nums text-[var(--text-primary)]">{{ num(template.installs) }}</dd>
                        </div>
                        <div class="rounded-2xl bg-[var(--bg-muted)]/60 p-3">
                            <dt class="text-[11px] text-[var(--text-tertiary)]">Avaliação</dt>
                            <dd class="mt-0.5 text-sm font-medium tabular-nums text-[var(--text-primary)]">{{ template.rating ? `${template.rating} de 5` : '—' }}</dd>
                        </div>
                    </dl>
                </div>

                <div class="flex items-start gap-3 rounded-[22px] border border-dashed border-[var(--border-strong)] p-4">
                    <ShieldCheck class="mt-0.5 h-4 w-4 shrink-0 text-[var(--text-secondary)]" aria-hidden="true" />
                    <p class="text-xs text-[var(--text-secondary)]">Não vêm: credenciais, cupons, integrações, coprodutores, aulas da área de membros nem dados de compradores. Preços e textos ficam editáveis na sua conta.</p>
                </div>

                <PnPanel v-if="my_installs.length" title="Suas instalações" :pad="20">
                    <ul class="space-y-2">
                        <li v-for="(i, idx) in my_installs" :key="idx" class="rounded-2xl bg-[var(--bg-muted)]/60 p-3">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span class="text-sm tabular-nums text-[var(--text-primary)]">{{ fmt(i.at) }} · versão {{ i.version ?? '—' }}</span>
                                <span class="pn-badge" :class="IMPORT_BADGE[i.status] || 'pn-badge--neutral'">{{ IMPORT_STATUS[i.status] || i.status }}</span>
                            </div>
                            <Link v-if="i.import_uuid && i.status === 'ready'" :href="`/produtos/importar/${i.import_uuid}`" class="pn-btn pn-btn--secondary pn-btn--sm mt-2 !w-auto">
                                Confirmar <ArrowUpRight class="h-3.5 w-3.5" aria-hidden="true" />
                            </Link>
                        </li>
                    </ul>
                </PnPanel>
            </aside>

            <!-- Conteúdo principal -->
            <div class="min-w-0 space-y-6 lg:order-1">
                <PnPanel v-if="template.description" title="Sobre este template" :index="1">
                    <p class="whitespace-pre-line text-sm leading-relaxed text-[var(--text-secondary)]">{{ template.description }}</p>
                </PnPanel>

                <PnPanel v-if="summary" title="O que vem no template" :sub="template.kind === 'recovery' ? 'Etapas de e-mail da recuperação de vendas' : 'Produtos, ofertas e páginas incluídos'" :index="2">
                    <template v-if="template.kind === 'recovery'">
                        <p class="text-sm text-[var(--text-primary)]">{{ summary.steps }} etapas de e-mail para: {{ (summary.events || []).map((e) => EVENTS[e] || e).join(', ') }}.</p>
                        <div v-for="(cfg, ev) in content?.events || {}" :key="ev" class="mt-5">
                            <p class="mb-3 inline-flex items-center gap-1.5 rounded-full bg-[var(--sky-100)] px-2.5 py-0.5 text-[11px] font-medium text-[var(--sky-600)]">
                                <Mail class="h-3 w-3" aria-hidden="true" /> {{ EVENTS[ev] || ev }}
                            </p>
                            <ol class="relative ml-1 space-y-4 border-l border-[var(--border-strong)] pl-5">
                                <li v-for="(s, i) in cfg.steps" :key="ev + i" class="relative">
                                    <span class="absolute -left-[27px] top-1 flex h-3.5 w-3.5 items-center justify-center rounded-full bg-[var(--lime-300)] ring-4 ring-[var(--glass-bg)]" aria-hidden="true" />
                                    <p class="flex flex-wrap items-center gap-1.5 text-[11px] text-[var(--text-tertiary)]">
                                        Etapa {{ i + 1 }} <span aria-hidden="true">·</span> <Clock class="h-3 w-3" aria-hidden="true" /> após {{ s.delay_minutes }} min
                                    </p>
                                    <div class="mt-1.5 rounded-2xl bg-[var(--bg-muted)]/60 p-4">
                                        <p class="text-sm font-medium text-[var(--text-primary)]">{{ s.subject }}</p>
                                        <p class="mt-1.5 whitespace-pre-line text-sm text-[var(--text-secondary)]">{{ s.body }}</p>
                                    </div>
                                </li>
                            </ol>
                        </div>
                    </template>
                    <ul v-else class="space-y-3">
                        <li v-for="p in summary.products" :key="p.key" class="rounded-[22px] border border-[var(--border-strong)] p-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="flex min-w-0 items-center gap-2 text-sm font-medium text-[var(--text-primary)]">
                                    <Package class="h-4 w-4 shrink-0 text-[var(--text-tertiary)]" aria-hidden="true" /> <span class="truncate">{{ p.name }}</span>
                                </p>
                                <span class="pn-badge pn-badge--neutral pn-badge--nodot">landing {{ p.landing_mode === 'blocks' ? 'por blocos' : 'por seções' }}</span>
                            </div>
                            <dl class="mt-3 grid grid-cols-3 gap-2">
                                <div class="rounded-2xl bg-[var(--bg-muted)]/60 p-3">
                                    <dt class="text-[11px] text-[var(--text-tertiary)]">Ofertas</dt>
                                    <dd class="mt-0.5 text-sm font-medium tabular-nums text-[var(--text-primary)]">{{ p.offers }}</dd>
                                </div>
                                <div class="rounded-2xl bg-[var(--bg-muted)]/60 p-3">
                                    <dt class="text-[11px] text-[var(--text-tertiary)]">Planos</dt>
                                    <dd class="mt-0.5 text-sm font-medium tabular-nums text-[var(--text-primary)]">{{ p.plans }}</dd>
                                </div>
                                <div class="rounded-2xl bg-[var(--bg-muted)]/60 p-3">
                                    <dt class="text-[11px] text-[var(--text-tertiary)]">Order bumps</dt>
                                    <dd class="mt-0.5 text-sm font-medium tabular-nums text-[var(--text-primary)]">{{ p.order_bumps }}</dd>
                                </div>
                            </dl>
                            <p v-if="p.payment_methods_blocked_here?.length" class="mt-3 flex items-start gap-2 rounded-2xl bg-[var(--status-warning-bg)] px-3 py-2 text-xs text-[var(--status-warning-fg)]">
                                <TriangleAlert class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                Métodos indisponíveis na sua conta: {{ p.payment_methods_blocked_here.join(', ') }} (ficam desativados).
                            </p>
                        </li>
                    </ul>
                </PnPanel>

                <!-- Avaliações -->
                <PnPanel title="Avaliações" :sub="template.rating ? `${template.rating} de 5 · ${template.rating_count} avaliação(ões)` : 'Ainda sem nota média'" :index="3">
                    <template #right>
                        <span v-if="template.rating" class="flex shrink-0 items-center gap-0.5" :aria-label="`Nota ${template.rating} de 5`">
                            <Star v-for="n in 5" :key="n" class="h-4 w-4" :class="n <= Math.round(template.rating) ? 'fill-amber-400 text-amber-400' : 'text-[var(--ink-200)]'" aria-hidden="true" />
                        </span>
                    </template>

                    <div v-if="can_review" class="mb-5 rounded-[22px] border border-[var(--border-strong)] p-4">
                        <p class="text-xs font-medium text-[var(--text-secondary)]">{{ my_review ? 'Sua avaliação' : 'Avalie este template' }}</p>
                        <div class="mt-2 flex flex-wrap items-center gap-1" role="group" aria-label="Nota">
                            <button v-for="n in 5" :key="n" type="button" :aria-label="`${n} estrelas`" :aria-pressed="n <= review.rating"
                                class="rounded-full p-1 transition hover:bg-[var(--bg-muted)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)]" @click="review.rating = n">
                                <Star class="h-5 w-5" :class="n <= review.rating ? 'fill-amber-400 text-amber-400' : 'text-[var(--ink-300)]'" />
                            </button>
                            <span v-if="my_review" class="pn-badge ml-2" :class="my_review.status === 'approved' ? 'pn-badge--success' : my_review.status === 'pending' ? 'pn-badge--warning' : 'pn-badge--neutral'">
                                Sua avaliação: {{ my_review.status === 'approved' ? 'publicada' : my_review.status === 'pending' ? 'em moderação' : 'não publicada' }}
                            </span>
                        </div>
                        <label for="review-comment" class="sr-only">Comentário</label>
                        <textarea id="review-comment" v-model="review.comment" rows="2" maxlength="1000" placeholder="Comentário (opcional)"
                            class="mt-3 w-full rounded-[20px] border border-[var(--border-strong)] bg-[var(--glass-bg)] px-4 py-3 text-sm text-[var(--text-primary)] outline-none transition focus:ring-4 focus:ring-[var(--focus-ring)]" />
                        <div class="mt-3 flex justify-end">
                            <button type="button" class="pn-btn pn-btn--primary pn-btn--sm !w-auto" :disabled="review.processing" @click="sendReview">
                                {{ my_review ? 'Atualizar avaliação' : 'Avaliar' }}
                            </button>
                        </div>
                    </div>

                    <ul class="divide-y divide-[var(--border-strong)]">
                        <li v-for="(r, idx) in reviews" :key="idx" class="py-3 first:pt-0 last:pb-0">
                            <div class="flex items-center gap-2">
                                <span class="flex items-center gap-0.5" :aria-label="`${r.rating} de 5 estrelas`">
                                    <Star v-for="n in 5" :key="n" class="h-3.5 w-3.5" :class="n <= r.rating ? 'fill-amber-400 text-amber-400' : 'text-[var(--ink-200)]'" aria-hidden="true" />
                                </span>
                                <span class="text-xs text-[var(--text-tertiary)]">{{ fmt(r.at) }}</span>
                            </div>
                            <p v-if="r.comment" class="mt-1.5 text-sm text-[var(--text-primary)]">{{ r.comment }}</p>
                        </li>
                        <li v-if="!reviews.length" class="flex flex-col items-center gap-2 py-6 text-center">
                            <MessageSquare class="h-6 w-6 text-[var(--text-tertiary)]" aria-hidden="true" />
                            <p class="text-sm text-[var(--text-tertiary)]">Ainda sem avaliações.</p>
                        </li>
                    </ul>
                </PnPanel>

                <!-- Versões -->
                <PnPanel v-if="versions.length" title="Versões" sub="Histórico de versões aprovadas" :index="4">
                    <template #right>
                        <History class="h-4 w-4 shrink-0 text-[var(--text-tertiary)]" aria-hidden="true" />
                    </template>
                    <ol class="relative ml-1 space-y-4 border-l border-[var(--border-strong)] pl-5">
                        <li v-for="v in versions" :key="v.version" class="relative">
                            <span class="absolute -left-[25px] top-1.5 h-2.5 w-2.5 rounded-full" :class="v.version === current_version ? 'bg-[var(--lime-400)] ring-1 ring-[var(--lime-600)]/40' : 'bg-[var(--ink-200)]'" aria-hidden="true" />
                            <p class="flex flex-wrap items-center gap-2 text-sm">
                                <strong class="font-medium text-[var(--text-primary)]">v{{ v.version }}</strong>
                                <span v-if="v.version === current_version" class="pn-badge pn-badge--brand">atual</span>
                                <span class="text-xs text-[var(--text-tertiary)]">{{ fmt(v.at) }}</span>
                            </p>
                            <p v-if="v.changelog" class="mt-0.5 text-sm text-[var(--text-secondary)]">{{ v.changelog }}</p>
                        </li>
                    </ol>
                </PnPanel>
            </div>
        </div>
    </div>
</template>
