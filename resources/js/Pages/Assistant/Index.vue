<script setup>
import { computed, nextTick, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import LayoutInfoprodutor from '@/Layouts/LayoutInfoprodutor.vue';
import PnPageHeader from '@/components/paynext/PnPageHeader.vue';
import PnEmptyState from '@/components/paynext/PnEmptyState.vue';
import PnMeter from '@/components/paynext/PnMeter.vue';
import { Sparkles, Plus, MessageSquare, ShieldCheck, Send, LoaderCircle, Search } from 'lucide-vue-next';

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

/* Apenas exibição: uso diário em % para o medidor. */
const usagePct = computed(() => {
    const limit = Number(usage.value.daily_limit) || 0;
    return limit > 0 ? Math.min(100, (Number(usage.value.messages_today) / limit) * 100) : 0;
});

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
    <div class="space-y-6">
        <PnPageHeader
            title="Assistente"
            :icon="Sparkles"
            description="Pergunte sobre vendas, produtos, campanhas, assinaturas e saldo. Ele só consulta os dados da sua conta: não muda nada nem faz saques. Confira números importantes nas telas de origem."
        >
            <template v-if="usage.configured" #actions>
                <div class="pn-glass flex min-w-[200px] flex-col gap-2 px-4 py-3">
                    <div class="flex items-baseline justify-between gap-3 text-xs">
                        <span class="text-[var(--text-secondary)]">Perguntas hoje</span>
                        <span class="font-medium tabular-nums text-[var(--text-primary)]">{{ usage.messages_today }}/{{ usage.daily_limit }}</span>
                    </div>
                    <PnMeter :value="usagePct" :show-marker="false" class="!h-1.5" />
                </div>
            </template>
        </PnPageHeader>

        <PnEmptyState
            v-if="!usage.configured"
            :icon="Sparkles"
            title="O assistente ainda não foi configurado pela plataforma"
            description="Quando estiver ativo, ele aparece aqui."
        />

        <div v-else class="grid gap-4 lg:grid-cols-[260px_minmax(0,1fr)]">
            <!-- Conversas -->
            <aside class="pn-glass flex flex-col gap-3 p-3 lg:h-[70vh]" aria-label="Conversas">
                <button type="button" class="pn-btn pn-btn--secondary pn-btn--sm pn-btn--block" @click="open(null)">
                    <Plus class="h-4 w-4" aria-hidden="true" /> Nova conversa
                </button>
                <p class="px-2 text-[11px] font-medium uppercase tracking-[0.08em] text-[var(--text-tertiary)]">Histórico</p>
                <nav class="-mx-1 flex max-h-48 flex-col gap-0.5 overflow-y-auto px-1 lg:max-h-none lg:flex-1">
                    <button
                        v-for="c in conversations"
                        :key="c.id"
                        type="button"
                        class="flex w-full items-center gap-2 rounded-full px-3 py-2 text-left text-[13px] transition focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)]"
                        :class="c.id === conversationId ? 'bg-[var(--text-primary)] font-medium text-[var(--text-inverse)]' : 'text-[var(--text-secondary)] hover:bg-[var(--bg-muted)] hover:text-[var(--text-primary)]'"
                        :aria-current="c.id === conversationId ? 'true' : undefined"
                        @click="open(c.id)"
                    >
                        <MessageSquare class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                        <span class="min-w-0 flex-1 truncate">{{ c.title }}</span>
                    </button>
                    <p v-if="!conversations.length" class="px-3 py-4 text-xs text-[var(--text-tertiary)]">Nenhuma conversa ainda. Faça a primeira pergunta ao lado.</p>
                </nav>
            </aside>

            <!-- Conversa -->
            <section class="pn-glass flex h-[70vh] min-h-[420px] flex-col overflow-hidden" aria-label="Conversa com o assistente">
                <div class="flex items-center gap-2 border-b border-[var(--border-strong)] px-4 py-3 text-xs text-[var(--text-secondary)]">
                    <ShieldCheck class="h-4 w-4 shrink-0 text-[var(--text-tertiary)]" aria-hidden="true" />
                    <span>Só consulta os dados da sua conta: não muda nada nem faz saques.</span>
                </div>

                <div ref="list" class="flex-1 space-y-4 overflow-y-auto p-4 sm:p-5" aria-live="polite">
                    <div v-if="!messages.length" class="flex flex-col items-center gap-5 py-6 text-center">
                        <span class="flex h-12 w-12 items-center justify-center rounded-full bg-[var(--lime-300)] text-[var(--ink-900)]">
                            <Sparkles class="h-6 w-6" aria-hidden="true" />
                        </span>
                        <div>
                            <p class="text-sm font-medium text-[var(--text-primary)]">Comece com uma pergunta</p>
                            <p class="mt-1 text-xs text-[var(--text-tertiary)]">Escolha uma sugestão ou escreva a sua abaixo.</p>
                        </div>
                        <div class="grid w-full max-w-2xl gap-2 sm:grid-cols-2">
                            <button
                                v-for="s in SUGGESTIONS"
                                :key="s"
                                type="button"
                                class="rounded-[18px] border border-[var(--border-strong)] bg-[var(--glass-bg)] px-4 py-3 text-left text-[13px] text-[var(--text-primary)] transition hover:bg-[var(--glass-bg-strong)] focus-visible:outline-none focus-visible:ring-4 focus-visible:ring-[var(--focus-ring)]"
                                @click="send(s)"
                            >
                                {{ s }}
                            </button>
                        </div>
                    </div>

                    <div v-for="m in messages" :key="m.id" class="flex gap-2.5" :class="m.role === 'user' ? 'justify-end' : 'justify-start'">
                        <span v-if="m.role !== 'user'" class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-[var(--lime-300)] text-[var(--ink-900)]" aria-hidden="true">
                            <Sparkles class="h-3.5 w-3.5" />
                        </span>
                        <div
                            class="max-w-[85%] rounded-[20px] px-4 py-2.5 text-sm leading-6"
                            :class="m.role === 'user'
                                ? 'rounded-br-md bg-[var(--text-primary)] text-[var(--text-inverse)]'
                                : (m.status === 'error'
                                    ? 'rounded-bl-md border border-[var(--status-error-fg)]/30 bg-[var(--status-error-bg)] text-[var(--status-error-fg)]'
                                    : 'rounded-bl-md bg-[var(--bg-muted)] text-[var(--text-primary)]')"
                        >
                            <p class="whitespace-pre-line break-words">{{ m.content }}</p>
                            <p v-if="m.tools?.length" class="mt-2 flex flex-wrap items-center gap-1 text-[11px] text-[var(--text-tertiary)]">
                                <Search class="h-3 w-3" aria-hidden="true" />
                                Consultou: {{ m.tools.map((t) => TOOL_LABEL[t] || t).join(', ') }}
                            </p>
                        </div>
                    </div>

                    <p v-if="sending" class="flex items-center gap-2 text-sm text-[var(--text-tertiary)]">
                        <LoaderCircle class="h-4 w-4 animate-spin motion-reduce:animate-none" aria-hidden="true" /> Consultando seus dados…
                    </p>
                </div>

                <form class="border-t border-[var(--border-strong)] p-3" @submit.prevent="send()">
                    <p v-if="error" class="mb-2 rounded-xl bg-[var(--status-error-bg)] px-3 py-2 text-sm text-[var(--status-error-fg)]" role="alert">{{ error }}</p>
                    <div class="flex gap-2">
                        <label for="assistant-input" class="sr-only">Sua pergunta</label>
                        <input
                            id="assistant-input"
                            v-model="input"
                            maxlength="2000"
                            :disabled="sending"
                            placeholder="Pergunte sobre o seu negócio…"
                            class="h-11 min-w-0 flex-1 rounded-full border border-[var(--border-strong)] bg-[var(--glass-bg)] px-4 text-sm text-[var(--text-primary)] placeholder:text-[var(--text-tertiary)] outline-none transition focus:ring-4 focus:ring-[var(--focus-ring)] disabled:opacity-60"
                        />
                        <button type="submit" class="pn-btn pn-btn--primary h-11 shrink-0 !px-4 sm:!px-6" :disabled="sending || input.trim().length < 2">
                            <Send class="h-4 w-4" aria-hidden="true" /><span class="hidden sm:inline">Enviar</span><span class="sr-only sm:hidden">Enviar</span>
                        </button>
                    </div>
                </form>
            </section>
        </div>
    </div>
</template>
