<script setup>
import { router, useForm } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';

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
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold">Biblioteca de templates</h1>
            <p class="mt-1 max-w-3xl text-sm text-zinc-500">Revise antes de publicar: o pacote é instalado pelo importador oficial (sem credenciais, cupons, integrações, coprodutores ou compradores), mas textos, imagens e landing ficam públicos para todos os sellers.</p>
        </div>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="font-semibold">Aguardando revisão ({{ pending.length }})</h2>
            <div v-for="v in pending" :key="v.id" class="mt-3 rounded-xl border border-zinc-100 p-4 dark:border-zinc-800">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <p class="font-medium">{{ v.template?.title }} <span class="text-xs text-zinc-500">v{{ v.version }} · {{ v.template?.category }} · {{ v.template?.kind === 'recovery' ? 'textos de recuperação' : 'pacote de produto' }}</span></p>
                        <p class="text-xs text-zinc-500">Autor: {{ v.template?.author }} (conta #{{ v.template?.author_tenant_id ?? 'oficial' }})</p>
                        <p v-if="v.template?.tagline" class="mt-1 text-sm">{{ v.template.tagline }}</p>
                        <p v-if="v.changelog" class="mt-1 text-xs text-zinc-500">Mudanças: {{ v.changelog }}</p>
                    </div>
                    <div class="flex gap-2">
                        <Button size="sm" @click="decide(v, 'approve')">Aprovar</Button>
                        <Button size="sm" variant="outline" @click="decide(v, 'reject')">Recusar</Button>
                    </div>
                </div>
                <p v-if="v.template?.description" class="mt-2 whitespace-pre-line text-sm text-zinc-600 dark:text-zinc-300">{{ v.template.description }}</p>
                <ul v-if="v.summary?.products" class="mt-2 text-xs text-zinc-500">
                    <li v-for="p in v.summary.products" :key="p.key">{{ p.name }} · {{ p.offers }} ofertas · {{ p.plans }} planos · {{ p.order_bumps }} bumps · {{ v.summary.assets }} arquivos</li>
                </ul>
                <p v-else-if="v.summary?.steps" class="mt-2 text-xs text-zinc-500">{{ v.summary.steps }} etapas de e-mail</p>
            </div>
            <p v-if="!pending.length" class="mt-2 text-sm text-zinc-500">Nada pendente.</p>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="font-semibold">Publicar template oficial</h2>
            <p class="text-xs text-zinc-500">Envie um pacote exportado (.brasapay.zip) de um produto modelo. Até {{ max_mb }} MB.</p>
            <div class="mt-3 grid gap-3 md:grid-cols-2">
                <input type="file" accept=".zip" class="text-sm" @change="official.package = $event.target.files[0]" />
                <input v-model="official.title" maxlength="120" placeholder="Título" class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800" />
                <select v-model="official.category" class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800">
                    <option v-for="(label, key) in productCategories" :key="key" :value="key">{{ label }}</option>
                </select>
                <input v-model="official.tagline" maxlength="200" placeholder="Resumo" class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800" />
                <textarea v-model="official.description" rows="2" maxlength="5000" placeholder="Descrição" class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm md:col-span-2 dark:border-zinc-700 dark:bg-zinc-800" />
            </div>
            <p v-for="(e, k) in official.errors" :key="k" class="mt-1 text-sm text-red-600">{{ e }}</p>
            <Button class="mt-3" :disabled="official.processing || !official.package || !official.title" @click="sendOfficial">Publicar</Button>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="font-semibold">Avaliações para moderar ({{ reviews.length }})</h2>
            <ul class="mt-2 divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                <li v-for="r in reviews" :key="r.id" class="flex flex-wrap items-start justify-between gap-2 py-2">
                    <div><p><strong>{{ r.rating }}/5</strong> · {{ r.template }}</p><p v-if="r.comment" class="text-zinc-600 dark:text-zinc-300">{{ r.comment }}</p></div>
                    <div class="flex gap-2"><Button size="sm" @click="moderate(r, 'approve')">Publicar</Button><Button size="sm" variant="outline" @click="moderate(r, 'reject')">Recusar</Button></div>
                </li>
                <li v-if="!reviews.length" class="py-2 text-zinc-500">Nada pendente.</li>
            </ul>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="font-semibold">Catálogo</h2>
            <table class="mt-2 w-full text-sm">
                <thead class="text-left text-xs text-zinc-500"><tr><th class="py-1">Template</th><th>Autor</th><th>Instalações</th><th>Nota</th><th>Status</th><th /></tr></thead>
                <tbody>
                    <tr v-for="t in templates" :key="t.id" class="border-t border-zinc-100 dark:border-zinc-800">
                        <td class="py-1.5">{{ t.title }}</td><td>{{ t.is_official ? 'Oficial' : t.author }}</td><td>{{ t.installs }}</td><td>{{ t.rating ?? '—' }}</td>
                        <td>{{ t.status === 'published' ? 'publicado' : 'fora do catálogo' }}</td>
                        <td class="text-right"><button type="button" class="text-xs underline" @click="togglePublished(t)">{{ t.status === 'published' ? 'Tirar do catálogo' : 'Republicar' }}</button></td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</template>
