<script setup>
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import {
    AlignCenter,
    AlignJustify,
    AlignLeft,
    AlignRight,
    Bold,
    Italic,
    Link as LinkIcon,
    ListOrdered,
    RemoveFormatting,
    Strikethrough,
} from 'lucide-vue-next';
import { looksLikeLessonHtml, serializeLessonEditorContent, sanitizeLessonHtml } from '@/lib/lessonDescription';

const props = defineProps({
    modelValue: { type: String, default: '' },
    placeholder: { type: String, default: '' },
});

const emit = defineEmits(['update:modelValue']);

const editorRef = ref(null);
const linkOpen = ref(false);
const linkUrl = ref('https://');
const colorOpen = ref(false);
const sizeOpen = ref(false);
let hydrating = false;

const COLORS = [
    { label: 'Padrão', value: '' },
    { label: 'Preto', value: '#18181b' },
    { label: 'Cinza', value: '#71717a' },
    { label: 'Vermelho', value: '#dc2626' },
    { label: 'Laranja', value: '#ea580c' },
    { label: 'Verde', value: '#16a34a' },
    { label: 'Azul', value: '#2563eb' },
    { label: 'Roxo', value: '#7c3aed' },
];

const SIZES = [
    { label: 'Pequeno', value: '2' },
    { label: 'Normal', value: '3' },
    { label: 'Grande', value: '5' },
];

function loadContent(text) {
    const el = editorRef.value;
    if (!el) return;
    hydrating = true;
    const value = text ?? '';
    if (looksLikeLessonHtml(value)) {
        el.innerHTML = sanitizeLessonHtml(value);
    } else {
        el.textContent = value;
    }
    hydrating = false;
}

function currentInnerText() {
    return editorRef.value?.innerText ?? '';
}

function emitValue() {
    if (hydrating || !editorRef.value) return;
    emit('update:modelValue', serializeLessonEditorContent(editorRef.value.innerHTML, currentInnerText()));
}

function run(command, value = null) {
    editorRef.value?.focus();
    document.execCommand(command, false, value);
    emitValue();
}

function applyColor(color) {
    colorOpen.value = false;
    if (!color) {
        run('removeFormat');
        return;
    }
    run('foreColor', color);
}

function applySize(size) {
    sizeOpen.value = false;
    run('fontSize', size);
}

function onPaste(event) {
    event.preventDefault();
    const html = event.clipboardData?.getData('text/html') || '';
    const text = event.clipboardData?.getData('text/plain') || '';
    if (html && looksLikeLessonHtml(html)) {
        document.execCommand('insertHTML', false, sanitizeLessonHtml(html));
    } else {
        document.execCommand('insertText', false, text);
    }
    emitValue();
}

function openLinkPopover() {
    colorOpen.value = false;
    sizeOpen.value = false;
    const selection = window.getSelection?.()?.toString() ?? '';
    if (!selection.trim()) {
        alert('Selecione o texto que deve virar link. Ex.: “Baixar material”.');
        return;
    }
    linkUrl.value = 'https://';
    linkOpen.value = true;
    nextTick(() => document.getElementById('lesson-editor-link-input')?.focus());
}

function applyLink() {
    const raw = (linkUrl.value || '').trim();
    if (!raw) {
        linkOpen.value = false;
        return;
    }
    let href = raw;
    if (!/^https?:\/\//i.test(href) && !href.startsWith('/')) {
        href = `https://${href}`;
    }
    if (/^\s*javascript:/i.test(href) || /^\s*data:/i.test(href)) {
        alert('Use um link http ou https.');
        return;
    }
    editorRef.value?.focus();
    document.execCommand('createLink', false, href);
    editorRef.value?.querySelectorAll('a').forEach((a) => {
        a.setAttribute('target', '_blank');
        a.setAttribute('rel', 'noopener noreferrer');
    });
    linkOpen.value = false;
    emitValue();
}

function clearFormatting() {
    run('removeFormat');
    run('unlink');
}

function closeMenus() {
    colorOpen.value = false;
    sizeOpen.value = false;
    linkOpen.value = false;
}

function onDocumentPointerDown(event) {
    if (!event.target?.closest?.('[data-lesson-editor-root]')) {
        closeMenus();
    }
}

onMounted(() => {
    loadContent(props.modelValue);
    document.addEventListener('pointerdown', onDocumentPointerDown);
});

onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', onDocumentPointerDown);
});
</script>

<template>
    <div data-lesson-editor-root class="overflow-hidden rounded-lg border border-zinc-300 bg-white dark:border-zinc-600 dark:bg-zinc-800">
        <div class="flex flex-wrap items-center gap-0.5 border-b border-zinc-200 bg-zinc-50 px-1.5 py-1 dark:border-zinc-700 dark:bg-zinc-900/60">
            <button type="button" class="lesson-editor-btn" title="Alinhar à esquerda" @mousedown.prevent @click="run('justifyLeft')">
                <AlignLeft class="h-4 w-4" />
            </button>
            <button type="button" class="lesson-editor-btn" title="Centralizar" @mousedown.prevent @click="run('justifyCenter')">
                <AlignCenter class="h-4 w-4" />
            </button>
            <button type="button" class="lesson-editor-btn" title="Alinhar à direita" @mousedown.prevent @click="run('justifyRight')">
                <AlignRight class="h-4 w-4" />
            </button>
            <button type="button" class="lesson-editor-btn" title="Justificar" @mousedown.prevent @click="run('justifyFull')">
                <AlignJustify class="h-4 w-4" />
            </button>
            <span class="mx-1 h-4 w-px bg-zinc-200 dark:bg-zinc-700" />
            <div class="relative">
                <button type="button" class="lesson-editor-btn gap-1 px-1.5 text-[11px] font-medium" title="Cor do texto" @mousedown.prevent @click="colorOpen = !colorOpen; sizeOpen = false; linkOpen = false">
                    <span class="h-3.5 w-3.5 rounded-sm border border-zinc-300 bg-gradient-to-br from-zinc-900 via-red-500 to-sky-500 dark:border-zinc-600" />
                    Cor
                </button>
                <div v-if="colorOpen" class="absolute left-0 top-full z-10 mt-1 w-36 rounded-md border border-zinc-200 bg-white p-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-900">
                    <button
                        v-for="c in COLORS"
                        :key="c.label"
                        type="button"
                        class="flex w-full items-center gap-2 rounded px-2 py-1 text-left text-xs text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800"
                        @mousedown.prevent
                        @click="applyColor(c.value)"
                    >
                        <span class="h-3.5 w-3.5 rounded-sm border border-zinc-200 dark:border-zinc-600" :style="{ background: c.value || 'transparent' }" />
                        {{ c.label }}
                    </button>
                </div>
            </div>
            <div class="relative">
                <button type="button" class="lesson-editor-btn px-1.5 text-[11px] font-medium" title="Tamanho da fonte" @mousedown.prevent @click="sizeOpen = !sizeOpen; colorOpen = false; linkOpen = false">
                    A
                </button>
                <div v-if="sizeOpen" class="absolute left-0 top-full z-10 mt-1 w-32 rounded-md border border-zinc-200 bg-white p-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-900">
                    <button
                        v-for="s in SIZES"
                        :key="s.label"
                        type="button"
                        class="block w-full rounded px-2 py-1 text-left text-xs text-zinc-700 hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800"
                        @mousedown.prevent
                        @click="applySize(s.value)"
                    >
                        {{ s.label }}
                    </button>
                </div>
            </div>
            <span class="mx-1 h-4 w-px bg-zinc-200 dark:bg-zinc-700" />
            <button type="button" class="lesson-editor-btn" title="Negrito" @mousedown.prevent @click="run('bold')">
                <Bold class="h-4 w-4" />
            </button>
            <button type="button" class="lesson-editor-btn" title="Itálico" @mousedown.prevent @click="run('italic')">
                <Italic class="h-4 w-4" />
            </button>
            <button type="button" class="lesson-editor-btn" title="Tachado" @mousedown.prevent @click="run('strikeThrough')">
                <Strikethrough class="h-4 w-4" />
            </button>
            <button type="button" class="lesson-editor-btn" title="Lista numerada" @mousedown.prevent @click="run('insertOrderedList')">
                <ListOrdered class="h-4 w-4" />
            </button>
            <button type="button" class="lesson-editor-btn" title="Inserir link no texto selecionado" @mousedown.prevent @click="openLinkPopover">
                <LinkIcon class="h-4 w-4" />
            </button>
            <button type="button" class="lesson-editor-btn" title="Limpar formatação" @mousedown.prevent @click="clearFormatting">
                <RemoveFormatting class="h-4 w-4" />
            </button>
        </div>

        <div v-if="linkOpen" class="flex flex-wrap items-center gap-2 border-b border-zinc-200 bg-zinc-50 px-2 py-2 dark:border-zinc-700 dark:bg-zinc-900/40">
            <input
                id="lesson-editor-link-input"
                v-model="linkUrl"
                type="url"
                class="min-w-0 flex-1 rounded-md border border-zinc-300 bg-white px-2 py-1 text-xs dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-200"
                placeholder="https://..."
                @keydown.enter.prevent="applyLink"
                @keydown.escape.prevent="linkOpen = false"
            />
            <button type="button" class="rounded-md bg-zinc-900 px-2 py-1 text-xs font-medium text-white dark:bg-white dark:text-zinc-900" @click="applyLink">Aplicar</button>
            <button type="button" class="text-xs text-zinc-500 hover:underline" @click="linkOpen = false">Cancelar</button>
        </div>

        <div
            ref="editorRef"
            class="lesson-rich-text min-h-[200px] max-h-[min(52vh,420px)] overflow-y-auto whitespace-pre-wrap px-3 py-2 text-sm text-zinc-800 outline-none dark:text-zinc-200"
            contenteditable="true"
            :data-placeholder="placeholder"
            spellcheck="true"
            @input="emitValue"
            @paste="onPaste"
        />
    </div>
</template>
