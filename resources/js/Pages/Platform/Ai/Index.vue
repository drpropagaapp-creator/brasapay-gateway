<script setup>
import { useForm } from '@inertiajs/vue3';
import LayoutPlatform from '@/Layouts/LayoutPlatform.vue';
import Button from '@/components/ui/Button.vue';

defineOptions({ layout: LayoutPlatform });

/** Brasa AI (admin). Custos são estimativas pela tabela de preços do modelo, em dólar. */
const props = defineProps({
    settings: { type: Object, required: true },
    has_key: { type: Boolean, default: false },
    models: { type: Array, default: () => [] },
    efforts: { type: Array, default: () => [] },
    month: { type: Object, required: true },
    tenants: { type: Array, default: () => [] },
});

const usd = (micros) => `US$ ${(micros / 1_000_000).toFixed(2)}`;
const form = useForm({ ...props.settings, api_key: '', remove_key: false });
function save() {
    form.put('/plataforma/assistente-ia', { preserveScroll: true, onSuccess: () => { form.api_key = ''; form.remove_key = false; } });
}
</script>

<template>
    <div class="max-w-4xl space-y-6">
        <div>
            <h1 class="text-2xl font-semibold">Assistente IA</h1>
            <p class="mt-1 text-sm text-zinc-500">O assistente só consulta dados agregados da própria conta do seller, por ferramentas fixas (sem acesso livre ao banco), e não executa ações. Sem chave configurada, os sellers veem "configuração pendente".</p>
        </div>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="font-semibold">Configuração</h2>
            <div class="mt-3 grid gap-3 md:grid-cols-2">
                <label class="flex items-center gap-2 text-sm md:col-span-2"><input v-model="form.enabled" type="checkbox" /> Assistente ligado para os sellers</label>
                <label class="text-sm">Provedor<input value="Anthropic (Claude)" disabled class="mt-1 w-full rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 dark:border-zinc-700 dark:bg-zinc-800" /></label>
                <label class="text-sm">Chave da API
                    <input v-model="form.api_key" type="password" autocomplete="off" :placeholder="has_key ? 'Configurada (deixe vazio para manter)' : 'sk-ant-…'" class="mt-1 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-800" />
                </label>
                <label class="text-sm">Modelo
                    <select v-model="form.model" class="mt-1 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-800">
                        <option v-for="m in models" :key="m.id" :value="m.id">{{ m.label }} (US$ {{ m.input }} / {{ m.output }} por milhão de tokens)</option>
                    </select>
                </label>
                <label class="text-sm">Esforço
                    <select v-model="form.effort" class="mt-1 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-800">
                        <option v-for="e in efforts" :key="e" :value="e">{{ e }}</option>
                    </select>
                </label>
                <label class="text-sm">Perguntas por usuário/dia<input v-model.number="form.daily_messages_per_user" type="number" min="1" max="500" class="mt-1 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-800" /></label>
                <label class="text-sm">Orçamento por conta/mês (centavos de US$)<input v-model.number="form.monthly_budget_cents_per_tenant" type="number" min="0" class="mt-1 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-800" /></label>
                <label class="text-sm">Orçamento da plataforma/mês (centavos de US$)<input v-model.number="form.monthly_budget_cents_platform" type="number" min="0" class="mt-1 w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 dark:border-zinc-700 dark:bg-zinc-800" /></label>
                <label v-if="has_key" class="flex items-center gap-2 text-sm"><input v-model="form.remove_key" type="checkbox" /> Remover a chave salva</label>
            </div>
            <p v-for="(e, k) in form.errors" :key="k" class="mt-1 text-sm text-red-600">{{ e }}</p>
            <Button class="mt-3" :disabled="form.processing" @click="save">Salvar</Button>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-5 dark:border-zinc-700 dark:bg-zinc-900">
            <h2 class="font-semibold">Este mês</h2>
            <div class="mt-3 grid grid-cols-2 gap-3 md:grid-cols-4 text-sm">
                <div><p class="text-xs text-zinc-500">Respostas</p><p class="text-lg font-semibold">{{ month.answers }}</p></div>
                <div><p class="text-xs text-zinc-500">Falhas do provedor</p><p class="text-lg font-semibold">{{ month.errors }}</p></div>
                <div><p class="text-xs text-zinc-500">Custo estimado</p><p class="text-lg font-semibold">{{ usd(month.cost_micros) }}</p></div>
                <div><p class="text-xs text-zinc-500">Tempo médio</p><p class="text-lg font-semibold">{{ month.avg_ms ? (month.avg_ms / 1000).toFixed(1) + ' s' : '—' }}</p></div>
            </div>
            <p v-if="Object.keys(month.tools || {}).length" class="mt-3 text-xs text-zinc-500">Ferramentas mais usadas: {{ Object.entries(month.tools).map(([k, v]) => `${k} (${v})`).join(', ') }}</p>
            <table class="mt-4 w-full text-sm">
                <thead class="text-left text-xs text-zinc-500"><tr><th class="py-1">Conta</th><th>Respostas</th><th>Falhas</th><th>Custo</th></tr></thead>
                <tbody>
                    <tr v-for="t in tenants" :key="t.tenant_id" class="border-t border-zinc-100 dark:border-zinc-800">
                        <td class="py-1.5">{{ t.name || '#' + t.tenant_id }} <span class="text-xs text-zinc-500">{{ t.email }}</span></td><td>{{ t.answers }}</td><td>{{ t.errors }}</td><td>{{ usd(t.cost_micros) }}</td>
                    </tr>
                    <tr v-if="!tenants.length"><td colspan="4" class="py-3 text-zinc-500">Sem uso neste mês.</td></tr>
                </tbody>
            </table>
        </section>
    </div>
</template>
