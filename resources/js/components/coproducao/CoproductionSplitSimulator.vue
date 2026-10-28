<script setup>
import { onMounted, ref, watch } from 'vue';
import axios from 'axios';

/**
 * Simulação da divisão de uma venda direta: mesma regra, taxas e prazos que
 * o sistema usa ao creditar as carteiras (o cálculo é feito no servidor).
 */
const props = defineProps({
    productId: { type: [String, Number], required: true },
    // Muda quando a lista de co-produtores muda, para refazer a simulação.
    revision: { type: [String, Number], default: 0 },
});

const PRESETS = [100, 297, 1000];
const amount = ref(100);
const method = ref('pix');
const loading = ref(false);
const error = ref('');
const result = ref(null);
let timer = null;

const brl = (v) => Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });

async function simulate() {
    const value = Number(amount.value);
    if (!Number.isFinite(value) || value <= 0) {
        result.value = null;
        return;
    }
    loading.value = true;
    error.value = '';
    try {
        const { data } = await axios.get(`/produtos/${props.productId}/coproducers/simulacao`, {
            params: { amount: value, method: method.value },
        });
        result.value = data;
    } catch (e) {
        error.value = e?.response?.data?.message || 'Não foi possível simular agora.';
    } finally {
        loading.value = false;
    }
}

function schedule() {
    clearTimeout(timer);
    timer = setTimeout(simulate, 300);
}

watch([amount, method], schedule);
watch(() => props.revision, simulate);
onMounted(simulate);

function releaseLabel(p) {
    if (p.days_to_available === 0) return 'Na hora';
    return `D+${p.days_to_available}`;
}
</script>

<template>
    <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
        <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">Simulação da divisão</h3>
        <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-400">
            Quanto cada parte recebe numa venda direta. Convites ainda não aceitos aparecem como estimativa.
        </p>

        <div class="mt-4 flex flex-wrap items-center gap-2">
            <button
                v-for="p in PRESETS"
                :key="p"
                type="button"
                class="rounded-full border px-3 py-1.5 text-sm transition"
                :class="Number(amount) === p ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/10 font-medium text-[var(--color-primary)]' : 'border-zinc-300 text-zinc-700 hover:border-zinc-400 dark:border-zinc-600 dark:text-zinc-300'"
                @click="amount = p"
            >
                {{ brl(p) }}
            </button>
            <label class="flex items-center gap-2 text-sm text-zinc-600 dark:text-zinc-400">
                Outro valor
                <input v-model.number="amount" type="number" min="0.01" step="0.01" class="w-32 rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" />
            </label>
            <select v-model="method" class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm dark:border-zinc-600 dark:bg-zinc-900 dark:text-white" aria-label="Método de pagamento da simulação">
                <option value="pix">PIX</option>
                <option value="card">Cartão</option>
                <option value="boleto">Boleto</option>
            </select>
        </div>

        <p v-if="error" class="mt-4 text-sm text-red-600">{{ error }}</p>

        <div v-else-if="result" class="mt-5 overflow-x-auto" :class="{ 'opacity-60': loading }">
            <table class="w-full min-w-[640px] text-sm">
                <thead>
                    <tr class="border-b border-zinc-200 text-left text-xs uppercase tracking-wide text-zinc-500 dark:border-zinc-700">
                        <th class="py-2 pr-3 font-medium">Participante</th>
                        <th class="py-2 pr-3 text-right font-medium">Parte</th>
                        <th class="py-2 pr-3 text-right font-medium">Bruto</th>
                        <th class="py-2 pr-3 text-right font-medium">Taxa</th>
                        <th class="py-2 pr-3 text-right font-medium">Líquido</th>
                        <th class="py-2 text-right font-medium">Liberação</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(p, i) in result.participants" :key="i" class="border-b border-zinc-100 last:border-0 dark:border-zinc-700/60">
                        <td class="py-2.5 pr-3">
                            <span class="font-medium text-zinc-900 dark:text-white">{{ p.name }}</span>
                            <span v-if="p.pending_invite" class="ml-2 rounded bg-amber-100 px-1.5 py-0.5 text-[10px] text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">convite pendente</span>
                        </td>
                        <td class="py-2.5 pr-3 text-right tabular-nums text-zinc-600 dark:text-zinc-300">{{ p.percent.toLocaleString('pt-BR') }}%</td>
                        <td class="py-2.5 pr-3 text-right tabular-nums text-zinc-900 dark:text-white">{{ brl(p.gross) }}</td>
                        <td class="py-2.5 pr-3 text-right tabular-nums text-zinc-500">− {{ brl(p.fee) }}</td>
                        <td class="py-2.5 pr-3 text-right font-semibold tabular-nums text-emerald-700 dark:text-emerald-400">{{ brl(p.net) }}</td>
                        <td class="py-2.5 text-right text-zinc-600 dark:text-zinc-300">
                            {{ releaseLabel(p) }}
                            <span v-if="p.reserve_percent > 0" class="block text-[11px] text-zinc-500">{{ p.reserve_percent }}% em reserva</span>
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="text-xs text-zinc-500">
                        <td class="pt-3">Venda de {{ brl(result.amount) }}</td>
                        <td class="pt-3 text-right">100%</td>
                        <td class="pt-3 text-right tabular-nums">{{ brl(result.participants.reduce((s, p) => s + p.gross, 0)) }}</td>
                        <td colspan="3" class="pt-3 text-right">O líquido vai para o saldo pendente e libera no prazo indicado.</td>
                    </tr>
                </tfoot>
            </table>
            <p v-if="result.issues?.length" class="mt-3 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/30 dark:text-amber-300">
                As comissões configuradas passam de 100%: a divisão abaixo ignora os co-produtores até a soma ser corrigida.
            </p>
        </div>
    </div>
</template>
