<script setup>
import { computed, ref, watch } from 'vue';

/**
 * Logo enviado no admin, recortado da margem transparente: muitos logos chegam como
 * quadrados (ex.: 400×400) com o desenho numa faixa estreita, e ficariam minúsculos
 * encaixados pela altura. A área com desenho é detectada no canvas e só ela é exibida.
 * Se a imagem não puder ser lida (CORS), cai para <img> comum.
 */
const props = defineProps({
    src: { type: String, required: true },
    alt: { type: String, default: '' },
    height: { type: Number, default: 32 },
    maxWidth: { type: Number, default: 190 },
    /** Proporção máxima (largura/altura) aceita; acima disso usa o slot (ex.: logo largo no lugar de ícone). */
    maxAspect: { type: Number, default: 0 },
});

const cache = (globalThis.__pnLogoBoxes ??= new Map());
const box = ref(cache.get(props.src) ?? null);
const failed = ref(false);

function measure(img) {
    try {
        const w = img.naturalWidth;
        const h = img.naturalHeight;
        const canvas = document.createElement('canvas');
        canvas.width = w;
        canvas.height = h;
        const ctx = canvas.getContext('2d', { willReadFrequently: true });
        ctx.drawImage(img, 0, 0);
        const data = ctx.getImageData(0, 0, w, h).data;
        let minX = w;
        let minY = h;
        let maxX = -1;
        let maxY = -1;
        for (let y = 0; y < h; y++) {
            for (let x = 0; x < w; x++) {
                if (data[(y * w + x) * 4 + 3] > 12) {
                    if (x < minX) minX = x;
                    if (x > maxX) maxX = x;
                    if (y < minY) minY = y;
                    if (y > maxY) maxY = y;
                }
            }
        }
        const result = maxX < 0 ? { x: 0, y: 0, w, h, nw: w, nh: h } : { x: minX, y: minY, w: maxX - minX + 1, h: maxY - minY + 1, nw: w, nh: h };
        cache.set(props.src, result);
        box.value = result;
    } catch {
        failed.value = true;
    }
}

function load() {
    if (cache.has(props.src)) {
        box.value = cache.get(props.src);
        return;
    }
    box.value = null;
    failed.value = false;
    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = () => measure(img);
    img.onerror = () => (failed.value = true);
    img.src = props.src;
}

watch(() => props.src, load, { immediate: true });

const tooWide = computed(() => !!(props.maxAspect && box.value && box.value.w / box.value.h > props.maxAspect));

const style = computed(() => {
    const b = box.value;
    if (!b) return null;
    let scale = props.height / b.h;
    if (b.w * scale > props.maxWidth) scale = props.maxWidth / b.w;
    return {
        width: `${Math.round(b.w * scale)}px`,
        height: `${Math.round(b.h * scale)}px`,
        backgroundImage: `url("${props.src}")`,
        backgroundSize: `${b.nw * scale}px ${b.nh * scale}px`,
        backgroundPosition: `${-b.x * scale}px ${-b.y * scale}px`,
        backgroundRepeat: 'no-repeat',
    };
});
</script>

<template>
    <slot v-if="tooWide" />
    <span v-else-if="style" class="inline-block shrink-0" role="img" :aria-label="alt" :style="style" />
    <img v-else :src="src" :alt="alt" class="object-contain object-left" :style="{ height: `${height}px`, maxWidth: `${maxWidth}px` }" />
</template>
