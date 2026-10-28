<script setup>
import { ref, computed } from 'vue';
import axios from 'axios';
import { Upload, Loader2, X, Images, Trash2 } from 'lucide-vue-next';

const props = defineProps({
    modelValue: { type: String, default: '' },
    uploadUrl: { type: String, required: true },
    label: { type: String, default: 'Imagem' },
    accept: { type: String, default: 'image/*' },
    /** Tamanho ideal em texto, ex: "1200×630 px" */
    recommendedSize: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

const uploading = ref(false);
const error = ref('');

const previewUrl = computed(() => props.modelValue || null);

function getCsrfToken() {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    if (match) {
        try {
            return decodeURIComponent(match[1]);
        } catch (_) {}
    }
    return '';
}

async function onFileChange(e) {
    const file = e.target?.files?.[0];
    if (!file) return;
    error.value = '';
    uploading.value = true;
    try {
        const formData = new FormData();
        formData.append('image', file);
        const { data } = await axios.post(props.uploadUrl, formData, {
            headers: {
                'X-XSRF-TOKEN': getCsrfToken(),
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            withCredentials: true,
        });
        emit('update:modelValue', data.url || '');
    } catch (err) {
        const msg = err.response?.data?.message || err.response?.data?.errors?.image?.[0] || 'Falha no envio. Tente outra imagem.';
        error.value = msg;
    } finally {
        uploading.value = false;
        e.target.value = '';
    }
}

function remove() {
    emit('update:modelValue', '');
    error.value = '';
}

/** Biblioteca: mesmas imagens do produto (endpoint irmão de /checkout-upload). */
const libraryUrl = computed(() =>
    /\/checkout-upload$/.test(props.uploadUrl) ? props.uploadUrl.replace(/\/checkout-upload$/, '/midias') : '',
);
const libraryOpen = ref(false);
const libraryLoading = ref(false);
const libraryItems = ref([]);
const libraryError = ref('');

async function openLibrary() {
    libraryOpen.value = true;
    libraryError.value = '';
    libraryLoading.value = true;
    try {
        const { data } = await axios.get(libraryUrl.value, {
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            withCredentials: true,
        });
        libraryItems.value = Array.isArray(data?.items) ? data.items : [];
    } catch (_) {
        libraryError.value = 'Não foi possível carregar a biblioteca.';
    } finally {
        libraryLoading.value = false;
    }
}

function pick(item) {
    emit('update:modelValue', item.url || '');
    libraryOpen.value = false;
}

async function deleteItem(item) {
    if (!window.confirm('Apagar esta imagem da biblioteca?')) return;
    libraryError.value = '';
    try {
        await axios.delete(libraryUrl.value, {
            data: { path: item.path },
            headers: { 'X-XSRF-TOKEN': getCsrfToken(), Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            withCredentials: true,
        });
        libraryItems.value = libraryItems.value.filter((i) => i.path !== item.path);
    } catch (err) {
        libraryError.value = err.response?.data?.message || 'Não foi possível apagar.';
    }
}

const inputId = computed(() => `img-upload-${Math.random().toString(36).slice(2)}`);
</script>

<template>
    <div class="space-y-2">
        <div v-if="label || recommendedSize" class="flex flex-col gap-0.5">
            <label v-if="label" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">
                {{ label }}
            </label>
            <p v-if="recommendedSize" class="text-xs text-zinc-500 dark:text-zinc-400">
                Tamanho ideal: {{ recommendedSize }}
            </p>
        </div>
        <div
            class="flex flex-col items-stretch gap-2 rounded-xl border-2 border-dashed border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-600 dark:bg-zinc-800/50"
        >
            <div v-if="previewUrl" class="relative inline-block self-start">
                <img
                    :src="previewUrl"
                    alt="Preview"
                    class="max-h-32 rounded-lg object-contain"
                    @error="(e) => e?.target && (e.target.style.display = 'none')"
                />
                <button
                    type="button"
                    class="absolute -right-2 -top-2 flex h-6 w-6 items-center justify-center rounded-full bg-red-500 text-white shadow transition hover:bg-red-600"
                    aria-label="Remover imagem"
                    @click="remove"
                >
                    <X class="h-3.5 w-3.5" />
                </button>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <input
                    :id="inputId"
                    type="file"
                    :accept="accept"
                    class="hidden"
                    :disabled="uploading"
                    @change="onFileChange"
                />
                <label
                    :for="inputId"
                    class="inline-flex cursor-pointer items-center gap-2 rounded-xl border-2 border-zinc-200 bg-white px-4 py-2.5 text-sm font-medium text-zinc-700 transition hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:border-zinc-500 dark:hover:bg-zinc-700"
                >
                    <Loader2 v-if="uploading" class="h-4 w-4 animate-spin" />
                    <Upload v-else class="h-4 w-4" />
                    {{ uploading ? 'Enviando…' : 'Subir imagem' }}
                </label>
                <button
                    v-if="libraryUrl"
                    type="button"
                    class="inline-flex items-center gap-2 rounded-xl border-2 border-zinc-200 bg-white px-4 py-2.5 text-sm font-medium text-zinc-700 transition hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:border-zinc-500 dark:hover:bg-zinc-700"
                    @click="libraryOpen ? (libraryOpen = false) : openLibrary()"
                >
                    <Images class="h-4 w-4" />
                    Biblioteca
                </button>
            </div>
            <p v-if="error" class="text-sm font-medium text-red-600 dark:text-red-400">
                {{ error }}
            </p>
            <div v-if="libraryOpen" class="rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900">
                <p v-if="libraryLoading" class="flex items-center gap-2 text-sm text-zinc-500">
                    <Loader2 class="h-4 w-4 animate-spin" /> Carregando…
                </p>
                <p v-else-if="libraryError" class="text-sm font-medium text-red-600 dark:text-red-400">{{ libraryError }}</p>
                <p v-else-if="!libraryItems.length" class="text-sm text-zinc-500">Nenhuma imagem enviada neste produto ainda.</p>
                <div v-if="!libraryLoading && libraryItems.length" class="grid max-h-64 grid-cols-3 gap-2 overflow-y-auto sm:grid-cols-4">
                    <div v-for="item in libraryItems" :key="item.path" class="group relative">
                        <button
                            type="button"
                            class="block aspect-square w-full overflow-hidden rounded-lg border-2 border-transparent bg-zinc-100 transition hover:border-[var(--color-primary,#f97316)] dark:bg-zinc-800"
                            :class="{ '!border-[var(--color-primary,#f97316)]': item.url === modelValue }"
                            :title="item.name"
                            @click="pick(item)"
                        >
                            <img :src="item.url" :alt="item.name" loading="lazy" class="h-full w-full object-cover" />
                        </button>
                        <button
                            type="button"
                            class="absolute right-1 top-1 hidden h-6 w-6 items-center justify-center rounded-full bg-black/70 text-white group-hover:flex"
                            aria-label="Apagar imagem"
                            @click.stop="deleteItem(item)"
                        >
                            <Trash2 class="h-3.5 w-3.5" />
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
