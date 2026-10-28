<script setup>
import { reactive, ref } from 'vue';
import { router, Link, useForm } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import Button from '@/components/ui/Button.vue';
import { LibraryBig, Star, Download, BadgeCheck } from 'lucide-vue-next';

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
</script>

<template>
    <div class="space-y-6">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-semibold"><LibraryBig class="h-6 w-6" /> Biblioteca de templates</h1>
                <p class="mt-1 max-w-2xl text-sm text-zinc-500">
                    Kits, checkouts, landing pages e textos de recuperação prontos. Todos gratuitos e revisados pela equipe.
                    Instalar cria uma cópia na sua conta, que você confere antes de confirmar. Nenhum template traz credenciais, cupons, integrações, coprodutores ou dados de compradores.
                </p>
            </div>
            <Button v-if="can_publish" variant="outline" @click="showSubmit = !showSubmit; form.reset()">Publicar um template</Button>
        </div>

        <section v-if="showSubmit" class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900/80">
            <h2 class="font-semibold">{{ form.template_id ? 'Nova versão' : 'Publicar um template' }}</h2>
            <div class="mt-3 grid gap-3 md:grid-cols-2">
                <select v-if="!form.template_id" v-model="form.kind" class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    <option value="product_package">A partir de um produto meu</option>
                    <option value="recovery">Meus textos de recuperação de vendas</option>
                </select>
                <select v-if="form.kind === 'product_package'" v-model="form.product_id" class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    <option value="">Produto…</option>
                    <option v-for="p in products" :key="p.id" :value="p.id">{{ p.name }}</option>
                </select>
                <template v-if="!form.template_id">
                    <input v-model="form.title" maxlength="120" placeholder="Título" class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800" />
                    <select v-if="form.kind === 'product_package'" v-model="form.category" class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                        <option v-for="(label, key) in productCategories" :key="key" :value="key">{{ label }}</option>
                    </select>
                    <input v-model="form.tagline" maxlength="200" placeholder="Resumo em uma frase" class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm md:col-span-2 dark:border-zinc-700 dark:bg-zinc-800" />
                    <textarea v-model="form.description" rows="3" maxlength="5000" placeholder="Descrição: para quem é, o que vem no template" class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm md:col-span-2 dark:border-zinc-700 dark:bg-zinc-800" />
                </template>
                <input v-model="form.changelog" maxlength="1000" placeholder="O que mudou nesta versão (opcional)" class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm md:col-span-2 dark:border-zinc-700 dark:bg-zinc-800" />
            </div>
            <p v-if="form.kind === 'product_package'" class="mt-3 text-xs text-zinc-500">
                Vão no template: dados do produto, ofertas, planos, order bumps, checkout e landing. <strong>Não vão:</strong> aulas e comunidade da área de membros, coprodutores, cupons, integrações, credenciais e compradores.
            </p>
            <label class="mt-3 flex items-start gap-2 text-sm">
                <input v-model="form.confirm_public" type="checkbox" class="mt-0.5" />
                <span>Entendo que, depois de aprovado, este conteúdo fica disponível gratuitamente para todos os sellers da plataforma.</span>
            </label>
            <p v-for="(e, k) in form.errors" :key="k" class="mt-1 text-sm text-red-600">{{ e }}</p>
            <Button class="mt-3" :disabled="form.processing || !form.confirm_public || (form.kind === 'product_package' && !form.product_id) || (!form.template_id && !form.title)" @click="submit">Enviar para revisão</Button>
        </section>

        <section v-if="mine.length" class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900/80">
            <h2 class="font-semibold">Meus templates</h2>
            <ul class="mt-2 divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                <li v-for="t in mine" :key="t.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
                    <div>
                        <Link :href="`/biblioteca/${t.slug}`" class="font-medium hover:underline">{{ t.title }}</Link>
                        <span class="ml-2 text-xs text-zinc-500">{{ STATUS[t.status] }}<template v-if="t.pending_version"> · v{{ t.pending_version }} em revisão</template> · {{ t.installs }} instalações</span>
                        <p v-if="t.rejection_reason" class="text-xs text-red-600">Motivo: {{ t.rejection_reason }}</p>
                    </div>
                    <Button v-if="!t.pending_version" size="sm" variant="ghost" @click="newVersion(t)">Enviar nova versão</Button>
                </li>
            </ul>
        </section>

        <div class="flex flex-wrap gap-2">
            <input v-model="f.q" placeholder="Buscar" class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800" @keyup.enter="apply" />
            <select v-model="f.category" class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800" @change="apply">
                <option :value="null">Todas as categorias</option>
                <option v-for="(label, key) in categories" :key="key" :value="key">{{ label }}</option>
            </select>
            <select v-model="f.sort" class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800" @change="apply">
                <option value="popular">Mais instalados</option>
                <option value="rating">Melhor avaliados</option>
                <option value="new">Mais novos</option>
            </select>
        </div>

        <p v-if="!templates.length" class="rounded-2xl border border-dashed border-zinc-300 py-10 text-center text-sm text-zinc-500 dark:border-zinc-700">Nenhum template encontrado.</p>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            <Link v-for="t in templates" :key="t.id" :href="`/biblioteca/${t.slug}`" class="flex flex-col rounded-2xl border border-zinc-200 bg-white p-4 transition hover:border-zinc-300 hover:shadow-sm dark:border-zinc-700 dark:bg-zinc-900/80">
                <p class="text-xs text-zinc-500">{{ t.category_label }}</p>
                <p class="mt-1 font-semibold">{{ t.title }}</p>
                <p class="mt-1 line-clamp-2 flex-1 text-sm text-zinc-600 dark:text-zinc-300">{{ t.tagline }}</p>
                <div class="mt-3 flex items-center gap-3 text-xs text-zinc-500">
                    <span class="flex items-center gap-1"><BadgeCheck v-if="t.is_official" class="h-3.5 w-3.5 text-emerald-600" />{{ t.author }}</span>
                    <span class="flex items-center gap-1"><Download class="h-3.5 w-3.5" />{{ t.installs }}</span>
                    <span v-if="t.rating" class="flex items-center gap-1"><Star class="h-3.5 w-3.5" />{{ t.rating }} ({{ t.rating_count }})</span>
                    <span class="ml-auto font-medium text-emerald-700">Grátis</span>
                </div>
            </Link>
        </div>
    </div>
</template>
