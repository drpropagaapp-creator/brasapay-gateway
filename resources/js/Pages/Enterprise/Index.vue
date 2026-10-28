<script setup>
import { router, useForm } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import Button from '@/components/ui/Button.vue';
import { Globe, Copy } from 'lucide-vue-next';

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
</script>

<template>
    <div class="max-w-4xl space-y-6">
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-semibold"><Globe class="h-6 w-6" /> Marca e domínio</h1>
            <p class="mt-1 text-sm text-zinc-500">White-label muda só a apresentação: nome, cores e o domínio do checkout. Sua conta, saldo, saques e adquirentes continuam os mesmos.</p>
        </div>

        <p v-if="!account" class="rounded-2xl border border-dashed border-zinc-300 p-6 text-sm text-zinc-500 dark:border-zinc-700">White-label não está ativo nesta conta. Ele é liberado pela equipe da plataforma.</p>
        <p v-else-if="account.status !== 'active'" class="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-100">White-label suspenso pela plataforma. Os domínios próprios não respondem enquanto isso.</p>

        <template v-if="account">
            <section class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900/80">
                <h2 class="font-semibold">Marca</h2>
                <p class="text-xs text-zinc-500">Definida pela equipe da plataforma. Peça ajustes ao seu gerente de conta.</p>
                <dl class="mt-3 grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                    <dt class="text-zinc-500">Nome exibido</dt><dd>{{ branding.app_name || account.display_name || '—' }}</dd>
                    <dt class="text-zinc-500">Cor principal</dt>
                    <dd class="flex items-center gap-2"><span v-if="branding.theme_primary" class="inline-block h-4 w-4 rounded" :style="{ background: branding.theme_primary }" />{{ branding.theme_primary || '—' }}</dd>
                    <dt class="text-zinc-500">Logo</dt><dd class="truncate">{{ branding.app_logo || '—' }}</dd>
                </dl>
            </section>

            <section class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900/80">
                <h2 class="font-semibold">Domínio do checkout</h2>
                <p class="mt-1 text-sm text-zinc-500">
                    Use um subdomínio seu (ex.: <code>pagamento.suaempresa.com.br</code>). Nele abre só o checkout dos seus produtos.
                    Para ativar: 1) crie o registro TXT abaixo; 2) aponte o domínio para <code>{{ app_host }}</code> por um proxy com HTTPS (ex.: Cloudflare com proxy ligado); 3) clique em verificar.
                </p>
                <div v-if="can_manage" class="mt-3 flex flex-wrap gap-2">
                    <input v-model="form.host" placeholder="pagamento.suaempresa.com.br" maxlength="200" class="min-w-[260px] flex-1 rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800" />
                    <Button :disabled="form.processing || !form.host" @click="add">Adicionar</Button>
                </div>
                <p v-if="form.errors.host" class="mt-1 text-sm text-red-600">{{ form.errors.host }}</p>
                <p class="mt-1 text-xs text-zinc-500">Limite: {{ account.max_domains }} domínio(s).</p>

                <div v-for="d in domains" :key="d.id" class="mt-4 rounded-xl border border-zinc-100 p-4 dark:border-zinc-800">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="font-medium">{{ d.host }} <span class="ml-2 text-xs" :class="d.status === 'active' ? 'text-emerald-700' : 'text-zinc-500'">{{ STATUS[d.status] || d.status }}</span></p>
                        <div v-if="can_manage" class="flex gap-2">
                            <Button size="sm" variant="outline" @click="verify(d)">Verificar</Button>
                            <Button size="sm" variant="ghost" @click="remove(d)">Remover</Button>
                        </div>
                    </div>
                    <div class="mt-2 grid gap-2 text-xs sm:grid-cols-[110px_1fr]">
                        <span class="text-zinc-500">TXT (nome)</span>
                        <span class="flex items-center gap-1 break-all font-mono">{{ d.txt_name }} <button type="button" aria-label="Copiar" @click="copy(d.txt_name)"><Copy class="h-3.5 w-3.5" /></button></span>
                        <span class="text-zinc-500">TXT (valor)</span>
                        <span class="flex items-center gap-1 break-all font-mono">{{ d.txt_value }} <button type="button" aria-label="Copiar" @click="copy(d.txt_value)"><Copy class="h-3.5 w-3.5" /></button></span>
                    </div>
                    <p class="mt-2 text-xs text-zinc-500">DNS: {{ d.dns_ok ? 'ok' : 'pendente' }} · HTTPS: {{ d.https_ok ? 'ok' : 'pendente' }}<template v-if="d.last_error"> · {{ d.last_error }}</template></p>
                </div>
            </section>
        </template>
    </div>
</template>
