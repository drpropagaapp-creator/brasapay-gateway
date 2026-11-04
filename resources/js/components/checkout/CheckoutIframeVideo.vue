<script setup>
import { computed } from 'vue';

/**
 * Player próprio via iframe (ex.: https://drpropaga-vsl.vercel.app/p/xxxx).
 * O servidor já guarda só a URL https de um host permitido; aqui revalidamos o esquema.
 */
const props = defineProps({
    url: { type: String, default: '' },
});

const src = computed(() => {
    const u = String(props.url || '').trim();
    return /^https:\/\/[^\s"'<>]+$/i.test(u) ? u : '';
});
</script>

<template>
    <div v-if="src" class="mb-8" data-checkout="video-iframe">
        <div class="relative aspect-video overflow-hidden rounded-2xl shadow-xl ring-1 ring-black/5">
            <iframe
                :src="src"
                title="Vídeo"
                class="absolute inset-0 h-full w-full border-0"
                allow="autoplay; fullscreen; picture-in-picture"
                allowfullscreen
                referrerpolicy="strict-origin-when-cross-origin"
            />
        </div>
    </div>
</template>
