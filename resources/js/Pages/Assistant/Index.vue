<script setup>
import { nextTick, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import Button from '@/components/ui/Button.vue';
import { Sparkles, Plus } from 'lucide-vue-next';

defineOptions({ layout: LayoutInfoprodutor });

/** Brasa AI — responde com dados da conta; só consulta, nunca executa ações. */
const props = defineProps({
    usage: { type: Object, required: true },
    conversations: { type: Array, default: () => [] },
    current: { type: Object, default: null },
});

const TOOL_LABEL = {
    vendas_resumo: 'vendas', vendas_por_dia: 'vendas por dia', produtos_desempenho: 'produtos', funil_checkout: 'funil do checkout',
    campanhas: 'campanhas', alertas: 'alertas', assinaturas: 'assinaturas', testes_ab: 'testes A/B', recuperacao: 'recuperação', saldo: 'saldo',
};
const SUGGESTIONS = [
    'Quanto vendi hoje?', 'Qual produto converte mais?', 'Qual checkout tem mais abandono?', 'Por que meu faturamento caiu este mês?',
    'Qual campanha trouxe mais receita?', 'Quanto tenho disponível para saque?', 'Quais assinaturas estão atrasadas?', 'Quais produtos tiveram mais reembolsos?',
];

const messages = ref(props.current?.messages ?? []);
const conversationId = ref(props.current?.id ?? null);
const usage = ref({ ...props.usage });
const input = ref('');
const sending = ref(false);
const error = ref('');
const list = ref(null);

function open(id) {
    router.get('/assistente', id ? { c: id } : {}, { preserveScroll: false });
}
async function send(text) {
    const message = (text ?? input.value).trim();
    if (!message || sending.value) return;
    error.value = '';
    sending.value = true;
    messages.value.push({ id: `tmp-${Date.now()}`, role: 'user', content: message });
    input.value = '';
    await scroll();
    try {
        const r = await window.axios.post('/assistente/mensagens', { message, conversation_id: conversationId.value });
        conversationId.value = r.data.conversation_id;
        messages.value.push(r.data.answer);
        usage.value = r.data.usage;
    } catch (e) {
        error.value = e.response?.data?.errors?.message?.[0] || e.response?.data?.message || 'Não foi possível enviar.';
        messages.value.pop();
        input.value = message;
    } finally {
        sending.value = false;
        await scroll();
    }
}
async function scroll() {
    await nextTick();
    list.value?.scrollTo({ top: list.value.scrollHeight, behavior: 'smooth' });
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-semibold"><Sparkles class="h-6 w-6" /> Assistente</h1>
                <p class="mt-1 max-w-2xl text-sm text-zinc-500">Pergunte sobre vendas, produtos, campanhas, assinaturas e saldo. Ele só consulta os dados da sua conta: não muda nada nem faz saques. Confira números importantes nas telas de origem.</p>
            </div>
            <p v-if="usage.configured" class="text-xs text-zinc-500">{{ usage.messages_today }}/{{ usage.daily_limit }} perguntas hoje</p>
        </div>

        <p v-if="!usage.configured" class="rounded-2xl border border-dashed border-zinc-300 p-6 text-sm text-zinc-500 dark:border-zinc-700">
            O assistente ainda não foi configurado pela plataforma. Quando estiver ativo, ele aparece aqui.
        </p>

        <div v-else class="grid gap-4 lg:grid-cols-[220px_1fr]">
            <aside class="space-y-1">
                <Button size="sm" variant="outline" class="w-full" @click="open(null)"><Plus class="mr-1 h-4 w-4" /> Nova conversa</Button>
                <button v-for="c in conversations" :key="c.id" type="button" class="block w-full truncate rounded-lg px-2 py-1.5 text-left text-sm"
                    :class="c.id === conversationId ? 'bg-zinc-100 font-medium dark:bg-zinc-800' : 'text-zinc-600 hover:bg-zinc-50 dark:text-zinc-300 dark:hover:bg-zinc-800/50'" @click="open(c.id)">{{ c.title }}</button>
            </aside>

            <section class="flex h-[70vh] flex-col rounded-2xl border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-900/80">
                <div ref="list" class="flex-1 space-y-4 overflow-y-auto p-4">
                    <div v-if="!messages.length" class="grid gap-2 sm:grid-cols-2">
                        <button v-for="s in SUGGESTIONS" :key="s" type="button" class="rounded-xl border border-zinc-200 px-3 py-2 text-left text-sm hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-zinc-800" @click="send(s)">{{ s }}</button>
                    </div>
                    <div v-for="m in messages" :key="m.id" :class="m.role === 'user' ? 'flex justify-end' : ''">
                        <div class="max-w-[85%] rounded-2xl px-4 py-2.5 text-sm" :class="m.role === 'user' ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : (m.status === 'error' ? 'bg-red-50 text-red-900 dark:bg-red-950/30 dark:text-red-200' : 'bg-zinc-100 dark:bg-zinc-800')">
                            <p class="whitespace-pre-line">{{ m.content }}</p>
                            <p v-if="m.tools?.length" class="mt-1.5 text-[11px] text-zinc-500">Consultou: {{ m.tools.map((t) => TOOL_LABEL[t] || t).join(', ') }}</p>
                        </div>
                    </div>
                    <p v-if="sending" class="text-sm text-zinc-500">Consultando seus dados…</p>
                </div>
                <form class="border-t border-zinc-200 p-3 dark:border-zinc-700" @submit.prevent="send()">
                    <p v-if="error" class="mb-2 text-sm text-red-600">{{ error }}</p>
                    <div class="flex gap-2">
                        <input v-model="input" maxlength="2000" :disabled="sending" placeholder="Pergunte sobre o seu negócio…" class="flex-1 rounded-xl border border-zinc-200 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-800" />
                        <Button type="submit" :disabled="sending || input.trim().length < 2">Enviar</Button>
                    </div>
                </form>
            </section>
        </div>
    </div>
</template>
