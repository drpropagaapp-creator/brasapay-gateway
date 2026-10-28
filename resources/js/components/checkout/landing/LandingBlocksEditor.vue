<script setup>
import { computed, ref } from 'vue';
import ImageUpload from '@/components/checkout/ImageUpload.vue';
import { ArrowDown, ArrowUp, Copy, Plus, Trash2 } from 'lucide-vue-next';

/**
 * Editor por blocos da landing do checkout. O schema espelha
 * App\Support\Landing\LandingBlockSchema — o servidor revalida tudo ao salvar.
 */
const props = defineProps({
    modelValue: { type: Array, default: () => [] },
    uploadUrl: { type: String, required: true },
});
const emit = defineEmits(['update:modelValue']);

const CTA_OPTIONS = [
    { value: 'scroll_checkout', label: 'Ir para o pagamento' },
    { value: 'scroll_block', label: 'Ir para um bloco da página' },
    { value: 'url', label: 'Abrir link externo' },
];

// tipo: [label, grupo, campos]; campo: [chave, tipo, label, extra]
const TYPES = {
    hero: ['Hero (chamada principal)', 'Estrutura', [
        ['title', 'text', 'Título'], ['subtitle', 'multiline', 'Subtítulo'], ['image', 'image', 'Imagem'],
        ['button_text', 'text', 'Texto do botão'], ['button_action', 'cta', 'Ação do botão'], ['button_target', 'target', 'Destino'],
        ['layout', 'enum', 'Layout', [['stacked', 'Empilhado'], ['split', 'Lado a lado']]],
    ]],
    heading: ['Título', 'Texto', [['text', 'text', 'Texto'], ['level', 'enum', 'Tamanho', [['h2', 'Título de seção'], ['h1', 'Título grande'], ['h3', 'Título pequeno']]]]],
    subheading: ['Subtítulo', 'Texto', [['text', 'multiline', 'Texto']]],
    text: ['Texto formatado', 'Texto', [['html', 'html', 'Conteúdo (HTML simples)']]],
    image: ['Imagem', 'Mídia', [['url', 'image', 'Imagem'], ['alt', 'text', 'Texto alternativo'], ['link', 'text', 'Link ao clicar (opcional)']]],
    gallery: ['Galeria', 'Mídia', [['columns', 'number', 'Colunas', [1, 4]], ['items', 'list', 'Imagens', [['url', 'image', 'Imagem'], ['alt', 'text', 'Texto alternativo']]]]],
    video: ['Vídeo', 'Mídia', [['provider', 'enum', 'Origem', [['youtube', 'YouTube'], ['vturb', 'VTurb (configurado em Vídeo)']]], ['url', 'text', 'Link do YouTube']]],
    button: ['Botão (CTA)', 'Conversão', [['text', 'text', 'Texto'], ['action', 'cta', 'Ação'], ['target', 'target', 'Destino'], ['variant', 'enum', 'Estilo', [['solid', 'Preenchido'], ['outline', 'Contorno']]]]],
    benefits: ['Benefícios', 'Conversão', [['title', 'text', 'Título'], ['items', 'list', 'Benefícios', [['text', 'text', 'Benefício']]]]],
    features: ['Funcionalidades', 'Conteúdo', [['title', 'text', 'Título'], ['items', 'list', 'Itens', [['title', 'text', 'Título'], ['text', 'multiline', 'Descrição']]]]],
    cards: ['Cards', 'Conteúdo', [['title', 'text', 'Título'], ['items', 'list', 'Cards', [['title', 'text', 'Título'], ['text', 'multiline', 'Texto'], ['image', 'image', 'Imagem']]]]],
    testimonials: ['Depoimentos', 'Prova social', [['title', 'text', 'Título'], ['items', 'list', 'Depoimentos', [['name', 'text', 'Nome'], ['text', 'multiline', 'Depoimento'], ['image', 'image', 'Foto'], ['rating', 'number', 'Estrelas (0–5)', [0, 5]]]]]],
    faq: ['Perguntas frequentes', 'Conteúdo', [['title', 'text', 'Título'], ['items', 'list', 'Perguntas', [['q', 'text', 'Pergunta'], ['a', 'multiline', 'Resposta']]]]],
    plans: ['Comparativo de planos', 'Conversão', [['title', 'text', 'Título'], ['items', 'list', 'Planos', [['name', 'text', 'Nome'], ['price', 'text', 'Preço'], ['features', 'multiline', 'Itens (um por linha)'], ['highlight', 'bool', 'Destacar'], ['button_text', 'text', 'Texto do botão']]]]],
    pricing: ['Oferta e preço', 'Conversão', [['title', 'text', 'Título'], ['old_price', 'text', 'Preço anterior (riscado)'], ['price', 'text', 'Preço'], ['note', 'multiline', 'Observação'], ['button_text', 'text', 'Texto do botão']]],
    countdown: ['Contador regressivo', 'Conversão', [['title', 'text', 'Título'], ['mode', 'enum', 'Tipo', [['evergreen', 'Por visitante (minutos)'], ['fixed', 'Data fixa']]], ['minutes', 'number', 'Minutos', [1, 10080]], ['ends_at', 'datetime', 'Termina em']]],
    guarantee: ['Garantia', 'Prova social', [['title', 'text', 'Título'], ['text', 'multiline', 'Texto'], ['days', 'number', 'Dias', [0, 365]], ['image', 'image', 'Selo (opcional)']]],
    bonus: ['Bônus', 'Conteúdo', [['title', 'text', 'Título'], ['items', 'list', 'Bônus', [['title', 'text', 'Título'], ['text', 'multiline', 'Descrição'], ['value', 'text', 'Valor (ex.: R$ 197)']]]]],
    logos: ['Logos', 'Prova social', [['title', 'text', 'Título'], ['items', 'list', 'Logos', [['url', 'image', 'Logo'], ['alt', 'text', 'Nome']]]]],
    divider: ['Separador', 'Estrutura', []],
    spacer: ['Espaçamento', 'Estrutura', [['height', 'number', 'Altura (px)', [8, 240]]]],
    footer: ['Rodapé', 'Estrutura', [['text', 'multiline', 'Texto']]],
    html: ['HTML e CSS personalizados', 'Avançado', [['html', 'html', 'HTML'], ['css', 'code', 'CSS (aplicado só a este bloco)']]],
    checkout: ['Formulário de pagamento', 'Estrutura', []],
};

const GROUPS = ['Estrutura', 'Texto', 'Mídia', 'Conteúdo', 'Prova social', 'Conversão', 'Avançado'];
const DEFAULT_STYLE = { align: 'center', width: 'normal', pad_y: 32, pad_y_mobile: null, radius: 0, bg: '', text: '', font_scale: 100, font_scale_mobile: null, hide_mobile: false, hide_desktop: false };

const blocks = computed(() => props.modelValue || []);
const selectedId = ref(blocks.value[0]?.id || null);
const showLibrary = ref(false);
const selected = computed(() => blocks.value.find((b) => b.id === selectedId.value) || null);
const hasCheckout = computed(() => blocks.value.some((b) => b.type === 'checkout'));

function newId() {
    return `b${Math.random().toString(36).slice(2, 12)}`;
}

function defaultProps(type) {
    const out = {};
    for (const [key, kind, , extra] of TYPES[type][2]) {
        if (kind === 'list') out[key] = [];
        else if (kind === 'enum') out[key] = extra[0][0];
        else if (kind === 'cta') out[key] = 'scroll_checkout';
        else if (kind === 'number') out[key] = extra[0];
        else if (kind === 'bool') out[key] = false;
        else out[key] = '';
    }
    if (type === 'button') out.text = 'Quero garantir o meu';
    if (type === 'spacer') out.height = 32;
    if (type === 'countdown') out.minutes = 15;
    if (type === 'gallery') out.columns = 3;
    return out;
}

function commit(list) {
    emit('update:modelValue', list);
}

function add(type) {
    if (type === 'checkout' && hasCheckout.value) return;
    const block = { id: newId(), type, props: defaultProps(type), style: { ...DEFAULT_STYLE } };
    const list = [...blocks.value];
    const idx = list.findIndex((b) => b.id === selectedId.value);
    list.splice(idx >= 0 ? idx + 1 : list.length, 0, block);
    commit(list);
    selectedId.value = block.id;
    showLibrary.value = false;
}

function move(index, delta) {
    const list = [...blocks.value];
    const target = index + delta;
    if (target < 0 || target >= list.length) return;
    [list[index], list[target]] = [list[target], list[index]];
    commit(list);
}

function duplicate(index) {
    const src = blocks.value[index];
    if (src.type === 'checkout') return;
    const copy = JSON.parse(JSON.stringify(src));
    copy.id = newId();
    const list = [...blocks.value];
    list.splice(index + 1, 0, copy);
    commit(list);
    selectedId.value = copy.id;
}

function remove(index) {
    const list = [...blocks.value];
    const [removed] = list.splice(index, 1);
    commit(list);
    if (removed?.id === selectedId.value) selectedId.value = list[Math.max(0, index - 1)]?.id || null;
}

function updateSelected(mutator) {
    const list = blocks.value.map((b) => (b.id === selectedId.value ? JSON.parse(JSON.stringify(b)) : b));
    const target = list.find((b) => b.id === selectedId.value);
    if (!target) return;
    target.style = { ...DEFAULT_STYLE, ...(target.style || {}) };
    mutator(target);
    commit(list);
}

function setProp(key, value) {
    updateSelected((b) => {
        b.props[key] = value;
    });
}

function setStyle(key, value) {
    updateSelected((b) => {
        b.style[key] = value;
    });
}

function addItem(key, fields) {
    updateSelected((b) => {
        const item = {};
        for (const [k, kind] of fields) item[k] = kind === 'bool' ? false : kind === 'number' ? 0 : '';
        b.props[key] = [...(b.props[key] || []), item];
    });
}

function setItem(key, index, field, value) {
    updateSelected((b) => {
        b.props[key][index][field] = value;
    });
}

function removeItem(key, index) {
    updateSelected((b) => {
        b.props[key].splice(index, 1);
    });
}

function moveItem(key, index, delta) {
    updateSelected((b) => {
        const arr = b.props[key];
        const t = index + delta;
        if (t < 0 || t >= arr.length) return;
        [arr[index], arr[t]] = [arr[t], arr[index]];
    });
}

function summary(block) {
    const p = block.props || {};
    return p.title || p.text || p.name || (Array.isArray(p.items) ? `${p.items.length} itens` : '') || '';
}

const otherBlocks = computed(() => blocks.value.filter((b) => b.id !== selectedId.value && b.type !== 'checkout'));
const inputClass = 'w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-600 dark:bg-zinc-800 dark:text-white';
</script>

<template>
    <div class="space-y-4">
        <!-- Estrutura -->
        <div>
            <div class="mb-2 flex items-center justify-between">
                <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Estrutura da página</span>
                <button type="button" class="inline-flex items-center gap-1 rounded-lg bg-[var(--color-primary)] px-2.5 py-1.5 text-xs font-medium text-white" @click="showLibrary = !showLibrary">
                    <Plus class="h-3.5 w-3.5" /> Adicionar bloco
                </button>
            </div>

            <div v-if="showLibrary" class="mb-3 space-y-3 rounded-xl border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-700 dark:bg-zinc-900/50">
                <div v-for="group in GROUPS" :key="group">
                    <p class="mb-1.5 text-[11px] font-semibold uppercase tracking-wide text-zinc-500">{{ group }}</p>
                    <div class="flex flex-wrap gap-1.5">
                        <button
                            v-for="(def, type) in Object.fromEntries(Object.entries(TYPES).filter(([, d]) => d[1] === group))"
                            :key="type"
                            type="button"
                            class="rounded-lg border border-zinc-300 bg-white px-2.5 py-1 text-xs hover:border-[var(--color-primary)] disabled:opacity-40 dark:border-zinc-600 dark:bg-zinc-800"
                            :disabled="type === 'checkout' && hasCheckout"
                            @click="add(type)"
                        >
                            {{ def[0] }}
                        </button>
                    </div>
                </div>
            </div>

            <p v-if="!blocks.length" class="rounded-xl border border-dashed border-zinc-300 p-4 text-center text-xs text-zinc-500 dark:border-zinc-700">
                Comece adicionando um bloco "Hero" e o "Formulário de pagamento".
            </p>
            <p v-else-if="!hasCheckout" class="mb-2 rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:bg-amber-950/30 dark:text-amber-300">
                Sem o bloco "Formulário de pagamento", o formulário aparece no fim da página.
            </p>

            <ol class="space-y-1">
                <li
                    v-for="(block, idx) in blocks"
                    :key="block.id"
                    :class="['flex items-center gap-1 rounded-lg border px-2 py-1.5 text-sm', block.id === selectedId ? 'border-[var(--color-primary)] bg-[var(--color-primary)]/5' : 'border-zinc-200 dark:border-zinc-700']"
                >
                    <button type="button" class="min-w-0 flex-1 text-left" @click="selectedId = block.id">
                        <span class="font-medium">{{ TYPES[block.type]?.[0] || block.type }}</span>
                        <span v-if="summary(block)" class="block truncate text-[11px] text-zinc-500">{{ summary(block) }}</span>
                        <span v-if="block.style?.hide_mobile || block.style?.hide_desktop" class="text-[10px] text-zinc-400">
                            {{ block.style.hide_mobile ? 'oculto no mobile' : 'oculto no desktop' }}
                        </span>
                    </button>
                    <button type="button" class="rounded p-1 text-zinc-500 hover:bg-zinc-100 disabled:opacity-30 dark:hover:bg-zinc-800" :disabled="idx === 0" :aria-label="`Subir ${TYPES[block.type]?.[0]}`" @click="move(idx, -1)"><ArrowUp class="h-3.5 w-3.5" /></button>
                    <button type="button" class="rounded p-1 text-zinc-500 hover:bg-zinc-100 disabled:opacity-30 dark:hover:bg-zinc-800" :disabled="idx === blocks.length - 1" :aria-label="`Descer ${TYPES[block.type]?.[0]}`" @click="move(idx, 1)"><ArrowDown class="h-3.5 w-3.5" /></button>
                    <button v-if="block.type !== 'checkout'" type="button" class="rounded p-1 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" :aria-label="`Duplicar ${TYPES[block.type]?.[0]}`" @click="duplicate(idx)"><Copy class="h-3.5 w-3.5" /></button>
                    <button type="button" class="rounded p-1 text-zinc-500 hover:bg-red-50 hover:text-red-600 dark:hover:bg-red-950/40" :aria-label="`Remover ${TYPES[block.type]?.[0]}`" @click="remove(idx)"><Trash2 class="h-3.5 w-3.5" /></button>
                </li>
            </ol>
        </div>

        <!-- Propriedades -->
        <div v-if="selected" class="space-y-3 rounded-xl border border-zinc-200 p-3 dark:border-zinc-700">
            <p class="text-sm font-semibold">{{ TYPES[selected.type]?.[0] }}</p>
            <p v-if="selected.type === 'checkout'" class="text-xs text-zinc-500">
                Aqui entra o formulário oficial de pagamento da BrasaPay (resumo do pedido, order bumps e métodos de pagamento).
                Os blocos acima aparecem antes dele; os de baixo, depois.
            </p>

            <template v-for="[key, kind, label, extra] in TYPES[selected.type]?.[2] || []" :key="key">
                <ImageUpload v-if="kind === 'image'" :model-value="selected.props[key]" :upload-url="uploadUrl" :label="label" @update:model-value="setProp(key, $event)" />
                <label v-else-if="kind !== 'list'" class="block text-xs">
                    <span class="mb-1 block text-zinc-600 dark:text-zinc-400">{{ label }}</span>
                    <input v-if="kind === 'text'" :value="selected.props[key]" type="text" :class="inputClass" @input="setProp(key, $event.target.value)" />
                    <textarea v-else-if="kind === 'multiline'" :value="selected.props[key]" rows="3" :class="inputClass" @input="setProp(key, $event.target.value)" />
                    <textarea v-else-if="kind === 'html'" :value="selected.props[key]" rows="8" :class="[inputClass, 'font-mono text-xs']" placeholder="<h2>Título</h2><p>Texto…</p>" @input="setProp(key, $event.target.value)" />
                    <textarea v-else-if="kind === 'code'" :value="selected.props[key]" rows="6" :class="[inputClass, 'font-mono text-xs']" placeholder=".destaque { color: #0a986e; }" @input="setProp(key, $event.target.value)" />
                    <input v-else-if="kind === 'number'" :value="selected.props[key]" type="number" :min="extra[0]" :max="extra[1]" :class="inputClass" @input="setProp(key, Number($event.target.value))" />
                    <input v-else-if="kind === 'datetime'" :value="(selected.props[key] || '').slice(0, 16)" type="datetime-local" :class="inputClass" @input="setProp(key, $event.target.value)" />
                    <input v-else-if="kind === 'bool'" :checked="!!selected.props[key]" type="checkbox" @change="setProp(key, $event.target.checked)" />
                    <select v-else-if="kind === 'enum'" :value="selected.props[key]" :class="inputClass" @change="setProp(key, $event.target.value)">
                        <option v-for="[v, l] in extra" :key="v" :value="v">{{ l }}</option>
                    </select>
                    <select v-else-if="kind === 'cta'" :value="selected.props[key]" :class="inputClass" @change="setProp(key, $event.target.value)">
                        <option v-for="o in CTA_OPTIONS" :key="o.value" :value="o.value">{{ o.label }}</option>
                    </select>
                    <template v-else-if="kind === 'target'">
                        <select v-if="selected.props[key === 'button_target' ? 'button_action' : 'action'] === 'scroll_block'" :value="selected.props[key]" :class="inputClass" @change="setProp(key, $event.target.value)">
                            <option value="">Escolha o bloco</option>
                            <option v-for="b in otherBlocks" :key="b.id" :value="b.id">{{ TYPES[b.type]?.[0] }} — {{ summary(b) || b.id }}</option>
                        </select>
                        <input v-else-if="selected.props[key === 'button_target' ? 'button_action' : 'action'] === 'url'" :value="selected.props[key]" type="url" placeholder="https://" :class="inputClass" @input="setProp(key, $event.target.value)" />
                        <span v-else class="text-[11px] text-zinc-500">Rola até o formulário de pagamento.</span>
                    </template>
                    <span v-if="kind === 'html'" class="mt-1 block text-[11px] text-zinc-500">Scripts, iframes e eventos são removidos ao salvar.</span>
                    <span v-if="kind === 'code'" class="mt-1 block text-[11px] text-zinc-500">O CSS vale só dentro deste bloco; não altera o formulário de pagamento.</span>
                </label>

                <div v-else class="space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="text-xs text-zinc-600 dark:text-zinc-400">{{ label }}</span>
                        <button type="button" class="text-xs text-[var(--color-primary)] hover:underline" @click="addItem(key, extra)">+ Adicionar</button>
                    </div>
                    <div v-for="(item, i) in selected.props[key] || []" :key="i" class="space-y-2 rounded-lg bg-zinc-50 p-2 dark:bg-zinc-900/50">
                        <div class="flex justify-end gap-1">
                            <button type="button" class="rounded p-0.5 text-zinc-500 disabled:opacity-30" :disabled="i === 0" aria-label="Subir item" @click="moveItem(key, i, -1)"><ArrowUp class="h-3 w-3" /></button>
                            <button type="button" class="rounded p-0.5 text-zinc-500 disabled:opacity-30" :disabled="i === (selected.props[key] || []).length - 1" aria-label="Descer item" @click="moveItem(key, i, 1)"><ArrowDown class="h-3 w-3" /></button>
                            <button type="button" class="rounded p-0.5 text-red-500" aria-label="Remover item" @click="removeItem(key, i)"><Trash2 class="h-3 w-3" /></button>
                        </div>
                        <template v-for="[f, fk, fl, fe] in extra" :key="f">
                            <ImageUpload v-if="fk === 'image'" :model-value="item[f]" :upload-url="uploadUrl" :label="fl" @update:model-value="setItem(key, i, f, $event)" />
                            <label v-else class="block text-[11px]">
                                <span class="text-zinc-500">{{ fl }}</span>
                                <textarea v-if="fk === 'multiline'" :value="item[f]" rows="2" :class="inputClass" @input="setItem(key, i, f, $event.target.value)" />
                                <input v-else-if="fk === 'number'" :value="item[f]" type="number" :min="fe?.[0]" :max="fe?.[1]" :class="inputClass" @input="setItem(key, i, f, Number($event.target.value))" />
                                <input v-else-if="fk === 'bool'" :checked="!!item[f]" type="checkbox" class="ml-2" @change="setItem(key, i, f, $event.target.checked)" />
                                <input v-else :value="item[f]" type="text" :class="inputClass" @input="setItem(key, i, f, $event.target.value)" />
                            </label>
                        </template>
                    </div>
                </div>
            </template>

            <!-- Estilo e responsividade -->
            <details v-if="selected.type !== 'checkout'" class="rounded-lg border border-zinc-200 p-2 dark:border-zinc-700">
                <summary class="cursor-pointer text-xs font-medium">Estilo e responsividade</summary>
                <div class="mt-2 grid grid-cols-2 gap-2 text-[11px]">
                    <label>Alinhamento
                        <select :value="selected.style?.align || 'center'" :class="inputClass" @change="setStyle('align', $event.target.value)">
                            <option value="left">Esquerda</option><option value="center">Centro</option><option value="right">Direita</option>
                        </select>
                    </label>
                    <label>Largura
                        <select :value="selected.style?.width || 'normal'" :class="inputClass" @change="setStyle('width', $event.target.value)">
                            <option value="narrow">Estreita</option><option value="normal">Normal</option><option value="wide">Larga</option><option value="full">Tela toda</option>
                        </select>
                    </label>
                    <label>Espaço vertical (desktop)
                        <input :value="selected.style?.pad_y ?? 32" type="number" min="0" max="160" :class="inputClass" @input="setStyle('pad_y', Number($event.target.value))" />
                    </label>
                    <label>Espaço vertical (mobile)
                        <input :value="selected.style?.pad_y_mobile ?? ''" type="number" min="0" max="160" placeholder="igual" :class="inputClass" @input="setStyle('pad_y_mobile', $event.target.value === '' ? null : Number($event.target.value))" />
                    </label>
                    <label>Tamanho do texto % (desktop)
                        <input :value="selected.style?.font_scale ?? 100" type="number" min="75" max="150" :class="inputClass" @input="setStyle('font_scale', Number($event.target.value))" />
                    </label>
                    <label>Tamanho do texto % (mobile)
                        <input :value="selected.style?.font_scale_mobile ?? ''" type="number" min="75" max="150" placeholder="igual" :class="inputClass" @input="setStyle('font_scale_mobile', $event.target.value === '' ? null : Number($event.target.value))" />
                    </label>
                    <label>Fundo
                        <input :value="selected.style?.bg || '#ffffff'" type="color" class="h-9 w-full rounded" @input="setStyle('bg', $event.target.value)" />
                        <button v-if="selected.style?.bg" type="button" class="text-[10px] text-zinc-500 underline" @click="setStyle('bg', '')">sem fundo</button>
                    </label>
                    <label>Cor do texto
                        <input :value="selected.style?.text || '#111827'" type="color" class="h-9 w-full rounded" @input="setStyle('text', $event.target.value)" />
                        <button v-if="selected.style?.text" type="button" class="text-[10px] text-zinc-500 underline" @click="setStyle('text', '')">padrão</button>
                    </label>
                    <label>Cantos arredondados
                        <input :value="selected.style?.radius ?? 0" type="number" min="0" max="48" :class="inputClass" @input="setStyle('radius', Number($event.target.value))" />
                    </label>
                    <div class="flex flex-col justify-end gap-1">
                        <label class="flex items-center gap-1.5"><input :checked="!!selected.style?.hide_mobile" type="checkbox" @change="setStyle('hide_mobile', $event.target.checked)" /> Ocultar no mobile</label>
                        <label class="flex items-center gap-1.5"><input :checked="!!selected.style?.hide_desktop" type="checkbox" @change="setStyle('hide_desktop', $event.target.checked)" /> Ocultar no desktop</label>
                    </div>
                </div>
            </details>
        </div>
    </div>
</template>
