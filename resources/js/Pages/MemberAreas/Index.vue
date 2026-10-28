<script setup>
import { Link } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import { MonitorPlay, Plus, PencilRuler, ExternalLink, Settings2, BookOpen } from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

/** Atalho para as áreas de membros do seller: abre direto no editor de conteúdo. */
defineProps({
    areas: { type: Array, default: () => [] },
    can_create: { type: Boolean, default: false },
});

const num = (v) => new Intl.NumberFormat('pt-BR').format(Number(v) || 0);
const date = (iso) => (iso ? new Date(iso).toLocaleDateString('pt-BR') : '—');
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-semibold tracking-tight text-[var(--text-primary)]"><MonitorPlay class="h-6 w-6" /> Áreas de membros</h1>
                <p class="mt-1 max-w-xl text-sm text-[var(--text-secondary)]">Monte o conteúdo dos seus cursos: seções, módulos e aulas. Quem compra o produto ganha acesso automaticamente.</p>
            </div>
            <Link v-if="can_create" href="/produtos/create" class="pn-btn pn-btn--primary pn-btn--sm !w-auto"><Plus class="h-4 w-4" /> Nova área de membros</Link>
        </div>

        <div v-if="!areas.length" class="pn-glass flex flex-col items-center gap-3 p-10 text-center">
            <BookOpen class="h-9 w-9 text-[var(--text-tertiary)]" />
            <p class="text-sm font-medium text-[var(--text-primary)]">Você ainda não tem área de membros.</p>
            <p class="max-w-md text-xs text-[var(--text-tertiary)]">Crie um produto do tipo "Área de membros" e depois monte as aulas aqui. Produtos com link externo ou entregáveis por e-mail não usam esta área.</p>
            <Link v-if="can_create" href="/produtos/create" class="pn-btn pn-btn--primary pn-btn--sm !w-auto"><Plus class="h-4 w-4" /> Criar área de membros</Link>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <article v-for="a in areas" :key="a.id" class="pn-glass flex flex-col overflow-hidden">
                <div class="relative aspect-[16/7] bg-[var(--bg-muted)]">
                    <img v-if="a.image_url" :src="a.image_url" :alt="a.name" class="h-full w-full object-cover" loading="lazy" />
                    <div v-else class="flex h-full items-center justify-center"><MonitorPlay class="h-10 w-10 text-[var(--text-tertiary)]" /></div>
                    <span class="absolute left-3 top-3 rounded-full px-2.5 py-0.5 text-[11px] font-medium"
                        :class="a.is_active ? 'bg-[var(--lime-300)] text-[var(--ink-900)]' : 'bg-[var(--ink-100)] text-[var(--ink-600)]'">{{ a.is_active ? 'À venda' : 'Desativado' }}</span>
                </div>
                <div class="flex flex-1 flex-col gap-4 p-5">
                    <div>
                        <h2 class="line-clamp-2 text-base font-medium text-[var(--text-primary)]">{{ a.name }}</h2>
                        <p class="mt-0.5 text-xs text-[var(--text-tertiary)]">Atualizado em {{ date(a.updated_at) }}</p>
                    </div>
                    <dl class="grid grid-cols-4 gap-2 text-center">
                        <div v-for="m in [['Seções', a.sections], ['Módulos', a.modules], ['Aulas', a.lessons], ['Alunos', a.students]]" :key="m[0]" class="rounded-2xl bg-[var(--bg-muted)]/60 px-1 py-2">
                            <dd class="text-sm font-medium tabular-nums text-[var(--text-primary)]">{{ num(m[1]) }}</dd>
                            <dt class="text-[10.5px] text-[var(--text-tertiary)]">{{ m[0] }}</dt>
                        </div>
                    </dl>
                    <p v-if="!a.lessons" class="rounded-xl bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/30 dark:text-amber-200">Sem aulas ainda: quem comprar vai encontrar a área vazia.</p>
                    <div class="mt-auto flex flex-wrap gap-2">
                        <a :href="`/produtos/${a.id}/member-builder`" class="pn-btn pn-btn--primary pn-btn--sm !w-auto"><PencilRuler class="h-3.5 w-3.5" /> Editar conteúdo</a>
                        <a v-if="a.url" :href="a.url" target="_blank" rel="noopener" class="pn-btn pn-btn--secondary pn-btn--sm !w-auto"><ExternalLink class="h-3.5 w-3.5" /> Ver como aluno</a>
                        <Link :href="`/produtos/${a.id}/edit`" class="pn-btn pn-btn--ghost pn-btn--sm !w-auto"><Settings2 class="h-3.5 w-3.5" /> Produto</Link>
                    </div>
                </div>
            </article>
        </div>
    </div>
</template>
