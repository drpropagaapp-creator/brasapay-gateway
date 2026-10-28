<script setup>
import { Link, router, useForm } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import Button from '@/components/ui/Button.vue';
import { ArrowLeft, Star, BadgeCheck } from 'lucide-vue-next';

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
</script>

<template>
    <div class="max-w-4xl space-y-6">
        <Link href="/biblioteca" class="inline-flex items-center gap-1 text-sm text-zinc-500 hover:underline"><ArrowLeft class="h-4 w-4" /> Biblioteca</Link>

        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-xs text-zinc-500">{{ template.category_label }}<template v-if="current_version"> · versão {{ current_version }}</template></p>
                <h1 class="mt-1 text-2xl font-semibold">{{ template.title }}</h1>
                <p class="mt-1 text-zinc-600 dark:text-zinc-300">{{ template.tagline }}</p>
                <p class="mt-2 flex items-center gap-3 text-sm text-zinc-500">
                    <span class="flex items-center gap-1"><BadgeCheck v-if="template.is_official" class="h-4 w-4 text-emerald-600" />{{ template.author }}</span>
                    <span>{{ template.installs }} instalações</span>
                    <span v-if="template.rating" class="flex items-center gap-1"><Star class="h-4 w-4" />{{ template.rating }} ({{ template.rating_count }})</span>
                    <span class="font-medium text-emerald-700">Grátis</span>
                </p>
            </div>
            <div class="text-right">
                <Button v-if="can_install" @click="install">{{ my_installs.length ? 'Instalar de novo' : 'Instalar' }}</Button>
                <p v-if="update_available" class="mt-1 text-xs text-amber-700">Há uma versão nova desde a sua última instalação.</p>
                <p v-if="template.status !== 'published'" class="mt-1 text-xs text-zinc-500">Ainda não está no catálogo ({{ template.status }}).</p>
            </div>
        </div>

        <section v-if="template.description" class="whitespace-pre-line rounded-2xl border border-zinc-200 bg-white p-5 text-sm dark:border-zinc-700 dark:bg-zinc-900/80">{{ template.description }}</section>

        <section v-if="summary" class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900/80">
            <h2 class="font-semibold">O que vem no template</h2>
            <template v-if="template.kind === 'recovery'">
                <p class="mt-2 text-sm">{{ summary.steps }} etapas de e-mail para: {{ (summary.events || []).map((e) => EVENTS[e] || e).join(', ') }}.</p>
                <template v-for="(cfg, ev) in content?.events || {}" :key="ev">
                    <div v-for="(s, i) in cfg.steps" :key="ev + i" class="mt-3 rounded-xl bg-zinc-50 p-3 text-sm dark:bg-zinc-800/60">
                        <p class="text-xs text-zinc-500">{{ EVENTS[ev] || ev }} · etapa {{ i + 1 }} · após {{ s.delay_minutes }} min</p>
                        <p class="mt-1 font-medium">{{ s.subject }}</p>
                        <p class="mt-1 whitespace-pre-line text-zinc-600 dark:text-zinc-300">{{ s.body }}</p>
                    </div>
                </template>
            </template>
            <ul v-else class="mt-2 space-y-2 text-sm">
                <li v-for="p in summary.products" :key="p.key" class="rounded-xl bg-zinc-50 px-3 py-2 dark:bg-zinc-800/60">
                    <p class="font-medium">{{ p.name }}</p>
                    <p class="text-xs text-zinc-500">
                        {{ p.offers }} ofertas · {{ p.plans }} planos · {{ p.order_bumps }} order bumps · landing {{ p.landing_mode === 'blocks' ? 'por blocos' : 'por seções' }}
                    </p>
                    <p v-if="p.payment_methods_blocked_here?.length" class="text-xs text-amber-700">Métodos indisponíveis na sua conta: {{ p.payment_methods_blocked_here.join(', ') }} (ficam desativados).</p>
                </li>
            </ul>
            <p class="mt-3 text-xs text-zinc-500">Não vêm: credenciais, cupons, integrações, coprodutores, aulas da área de membros nem dados de compradores. Preços e textos ficam editáveis na sua conta.</p>
        </section>

        <section v-if="my_installs.length" class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900/80">
            <h2 class="font-semibold">Suas instalações</h2>
            <ul class="mt-2 text-sm">
                <li v-for="(i, idx) in my_installs" :key="idx" class="py-1">
                    {{ fmt(i.at) }} · versão {{ i.version ?? '—' }} · {{ IMPORT_STATUS[i.status] || i.status }}
                    <Link v-if="i.import_uuid && i.status === 'ready'" :href="`/produtos/importar/${i.import_uuid}`" class="ml-1 underline">confirmar</Link>
                </li>
            </ul>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900/80">
            <h2 class="font-semibold">Avaliações</h2>
            <div v-if="can_review" class="mt-3 rounded-xl bg-zinc-50 p-3 dark:bg-zinc-800/60">
                <div class="flex items-center gap-1">
                    <button v-for="n in 5" :key="n" type="button" :aria-label="`${n} estrelas`" @click="review.rating = n">
                        <Star class="h-5 w-5" :class="n <= review.rating ? 'fill-amber-400 text-amber-400' : 'text-zinc-300'" />
                    </button>
                    <span v-if="my_review" class="ml-2 text-xs text-zinc-500">Sua avaliação: {{ my_review.status === 'approved' ? 'publicada' : my_review.status === 'pending' ? 'em moderação' : 'não publicada' }}</span>
                </div>
                <textarea v-model="review.comment" rows="2" maxlength="1000" placeholder="Comentário (opcional)" class="mt-2 w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800" />
                <Button size="sm" class="mt-2" :disabled="review.processing" @click="sendReview">{{ my_review ? 'Atualizar avaliação' : 'Avaliar' }}</Button>
            </div>
            <ul class="mt-3 divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                <li v-for="(r, idx) in reviews" :key="idx" class="py-2">
                    <p class="flex items-center gap-0.5"><Star v-for="n in r.rating" :key="n" class="h-3.5 w-3.5 fill-amber-400 text-amber-400" /> <span class="ml-2 text-xs text-zinc-500">{{ fmt(r.at) }}</span></p>
                    <p v-if="r.comment" class="mt-1">{{ r.comment }}</p>
                </li>
                <li v-if="!reviews.length" class="py-3 text-zinc-500">Ainda sem avaliações.</li>
            </ul>
        </section>

        <section v-if="versions.length" class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900/80">
            <h2 class="font-semibold">Versões</h2>
            <ul class="mt-2 text-sm">
                <li v-for="v in versions" :key="v.version" class="py-1"><strong>v{{ v.version }}</strong> · {{ fmt(v.at) }}<template v-if="v.changelog"> · {{ v.changelog }}</template></li>
            </ul>
        </section>
    </div>
</template>
