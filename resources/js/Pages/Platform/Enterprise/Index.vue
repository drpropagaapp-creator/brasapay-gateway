<script setup>
import { reactive, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';

defineOptions({ layout: LayoutPlatform });

/** Brasa Enterprise (admin). White-label é só apresentação: não muda tenant financeiro nem credenciais. */
const props = defineProps({
    accounts: { type: Array, default: () => [] },
    branding_keys: { type: Array, default: () => [] },
});

const LABELS = { app_name: 'Nome exibido', theme_primary: 'Cor principal (#RRGGBB)', app_logo: 'Logo (https://)', app_logo_dark: 'Logo modo escuro (https://)', favicon_url: 'Favicon (https://)', login_hero_tagline: 'Frase da tela de login' };
const NAV = ['/vendas', '/crm', '/assinaturas', '/financeiro', '/relatorios', '/inteligencia', '/experimentos', '/biblioteca', '/recuperacao', '/integracoes', '/aplicacoes-api', '/desenvolvedores', '/usuarios/equipe'];

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
    <div class="space-y-6">
        <div>
            <h1 class="text-2xl font-semibold">White-label (Enterprise)</h1>
            <p class="mt-1 max-w-3xl text-sm text-zinc-500">Liberar marca e domínio próprio para um seller. Muda só a apresentação: o seller continua o mesmo tenant financeiro, com as mesmas adquirentes, carteira e responsabilidades. Toda alteração fica na auditoria.</p>
        </div>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="font-semibold">Ativar para um seller</h2>
            <div class="mt-3 flex flex-wrap gap-2">
                <input v-model="enable.seller" placeholder="E-mail ou ID do seller" class="min-w-[240px] rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800" />
                <input v-model="enable.display_name" placeholder="Nome comercial (opcional)" class="rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800" />
                <Button :disabled="enable.processing || !enable.seller" @click="doEnable">Ativar</Button>
            </div>
            <p v-for="(e, k) in enable.errors" :key="k" class="mt-1 text-sm text-red-600">{{ e }}</p>
        </section>

        <section v-for="a in accounts" :key="a.id" class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <div>
                    <p class="font-medium">{{ a.display_name || a.seller?.name }} <span class="text-xs" :class="a.status === 'active' ? 'text-emerald-700' : 'text-amber-700'">{{ a.status === 'active' ? 'ativo' : 'suspenso' }}</span></p>
                    <p class="text-xs text-zinc-500">{{ a.seller?.email }} · conta #{{ a.tenant_id }} · até {{ a.max_domains }} domínio(s)</p>
                    <p v-if="a.suspension_reason" class="text-xs text-amber-700">Motivo: {{ a.suspension_reason }}</p>
                </div>
                <div class="flex gap-2">
                    <Button size="sm" variant="outline" @click="open(a)">Editar</Button>
                    <Button size="sm" variant="ghost" @click="toggle(a)">{{ a.status === 'active' ? 'Suspender' : 'Reativar' }}</Button>
                </div>
            </div>
            <ul class="mt-2 text-sm">
                <li v-for="d in a.domains" :key="d.id" class="flex items-center gap-2 py-0.5">
                    <span class="font-mono text-xs">{{ d.host }}</span><span class="text-xs text-zinc-500">{{ d.status }}<template v-if="d.last_error"> · {{ d.last_error }}</template></span>
                    <button type="button" class="text-xs underline" @click="removeDomain(d)">remover</button>
                </li>
            </ul>

            <div v-if="editing === a.id" class="mt-4 space-y-3 rounded-xl bg-zinc-50 p-4 dark:bg-zinc-800/50">
                <div class="grid gap-3 md:grid-cols-2">
                    <label class="text-sm">Nome comercial<input v-model="edit.display_name" maxlength="120" class="mt-1 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-800" /></label>
                    <label class="text-sm">Limite de domínios<input v-model.number="edit.max_domains" type="number" min="0" max="20" class="mt-1 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-800" /></label>
                    <label v-for="k in branding_keys" :key="k" class="text-sm">{{ LABELS[k] || k }}<input v-model="edit.branding[k]" maxlength="500" class="mt-1 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-800" /></label>
                </div>
                <div>
                    <p class="text-sm font-medium">Esconder do menu do seller</p>
                    <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1">
                        <label v-for="n in NAV" :key="n" class="flex items-center gap-1 text-xs"><input v-model="edit.hidden_nav" type="checkbox" :value="n" />{{ n }}</label>
                    </div>
                    <p class="mt-1 text-xs text-zinc-500">Só esconde o item do menu; as permissões continuam as mesmas.</p>
                </div>
                <div class="flex gap-2"><Button size="sm" @click="save(a)">Salvar</Button><Button size="sm" variant="ghost" @click="editing = null">Cancelar</Button></div>
            </div>
        </section>
        <p v-if="!accounts.length" class="text-sm text-zinc-500">Nenhuma conta white-label ainda.</p>
    </div>
</template>
