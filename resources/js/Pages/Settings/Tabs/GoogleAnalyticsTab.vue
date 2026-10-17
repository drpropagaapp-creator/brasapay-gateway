<script setup>
defineProps({
    form: { type: Object, required: true },
});
</script>

<template>
    <section class="overflow-hidden rounded-xl border border-zinc-200 bg-white p-6 shadow-sm dark:border-zinc-700 dark:bg-zinc-800/50">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-base font-semibold text-zinc-900 dark:text-white">Google Analytics 4</h2>
                <p class="mt-1 max-w-2xl text-sm text-zinc-600 dark:text-zinc-400">
                    Credenciais da propriedade da plataforma. Com elas ativas, o checkout e a área de membros enviam visualizações de página, e cada pedido aprovado envia a compra. Os relatórios ficam no painel do Google Analytics.
                </p>
            </div>
            <span
                class="inline-flex shrink-0 rounded-full px-2.5 py-1 text-xs font-medium"
                :class="form.platform_ga4_enabled === '1'
                    ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/50 dark:text-emerald-200'
                    : 'bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300'"
            >
                {{ form.platform_ga4_enabled === '1' ? 'Coleta ativa' : 'Desativado' }}
            </span>
        </div>

        <label class="mt-5 flex cursor-pointer items-start gap-3 rounded-xl border border-zinc-200 bg-zinc-50/80 p-4 dark:border-zinc-600 dark:bg-zinc-800/80">
            <input
                type="checkbox"
                class="mt-0.5 h-4 w-4 rounded border-zinc-300 text-[var(--color-primary)] focus:ring-[var(--color-primary)]"
                :checked="form.platform_ga4_enabled === '1'"
                @change="form.platform_ga4_enabled = $event.target.checked ? '1' : '0'"
            />
            <span>
                <span class="block text-sm font-medium text-zinc-900 dark:text-white">Enviar dados para o GA4</span>
                <span class="mt-0.5 block text-xs text-zinc-500 dark:text-zinc-400">
                    O GA4 configurado em cada produto continua enviando para a conta do infoprodutor.
                </span>
            </span>
        </label>

        <div class="mt-5 space-y-4">
            <div>
                <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300" for="platform-ga4-measurement-id">
                    Measurement ID
                </label>
                <input
                    id="platform-ga4-measurement-id"
                    v-model="form.platform_ga4_measurement_id"
                    type="text"
                    autocomplete="off"
                    placeholder="G-XXXXXXXXXX"
                    class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none ring-[var(--color-primary)] focus:ring-2 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                />
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    No GA4: Administrador → Coleta e modificação de dados → Fluxos de dados → fluxo da Web. O ID começa com G-.
                </p>
                <p v-if="form.errors.platform_ga4_measurement_id" class="mt-1 text-xs text-red-600 dark:text-red-400">
                    {{ form.errors.platform_ga4_measurement_id }}
                </p>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium text-zinc-700 dark:text-zinc-300" for="platform-ga4-api-secret">
                    API Secret
                </label>
                <input
                    id="platform-ga4-api-secret"
                    v-model="form.platform_ga4_api_secret"
                    type="password"
                    autocomplete="new-password"
                    :placeholder="form.platform_ga4_api_secret_configured ? 'Segredo já salvo. Cole outro para substituir.' : 'Cole o API Secret'"
                    class="w-full rounded-xl border border-zinc-200 bg-white px-3 py-2.5 text-sm text-zinc-900 outline-none ring-[var(--color-primary)] focus:ring-2 dark:border-zinc-600 dark:bg-zinc-900 dark:text-white"
                />
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    No mesmo fluxo de dados: Measurement Protocol API secrets → Criar. Com o segredo salvo, a compra do pedido aprovado sai pelo servidor. Deixe em branco para manter o segredo atual.
                </p>
                <p v-if="form.platform_ga4_api_secret_configured" class="mt-1 text-xs text-emerald-700 dark:text-emerald-300">
                    API Secret configurado.
                </p>
                <p v-if="form.errors.platform_ga4_api_secret" class="mt-1 text-xs text-red-600 dark:text-red-400">
                    {{ form.errors.platform_ga4_api_secret }}
                </p>
            </div>
        </div>
    </section>
</template>
