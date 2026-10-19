<script setup>
import { computed, onBeforeUnmount, onMounted, ref, useAttrs } from 'vue';
import { Check } from 'lucide-vue-next';
import { router, usePage } from '@inertiajs/vue3';
import ThemeToggler from '@/components/layout/ThemeToggler.vue';
import LocaleFlag from '@/components/ui/LocaleFlag.vue';
import { useI18n } from '@/composables/useI18n';
import { usePanelColorScheme } from '@/composables/usePanelColorScheme';

defineOptions({ inheritAttrs: false });

const props = defineProps({
    /** Mantido por compatibilidade; o visual é sempre o do Paynext DS. */
    variant: { type: String, default: 'default' },
    themeStyle: { type: String, default: 'cycle' },
    size: { type: String, default: 'sm' },
    languageOnly: { type: Boolean, default: false },
    themeOnly: { type: Boolean, default: false },
});

const attrs = useAttrs();
const page = usePage();
const showLanguage = computed(() => !props.themeOnly && !!page.props?.auth?.user && !page.url.startsWith('/plataforma'));
const showTheme = computed(() => !props.languageOnly);

const { showToggler } = usePanelColorScheme();
const switchingLanguage = ref(false);
const languageOpen = ref(false);
const rootRef = ref(null);

const { t, locale, availableLanguages } = useI18n();
const currentLanguageName = computed(() => {
    const current = (availableLanguages.value || []).find((lang) => lang.code === locale.value);
    return current?.name || t('header.language', 'Idioma');
});
const btnStyle = computed(() => (props.size === 'md' ? '' : 'width:38px;height:38px'));

async function switchLanguage(nextLocale) {
    if (!nextLocale || switchingLanguage.value || nextLocale === locale.value) return;
    switchingLanguage.value = true;
    try {
        await window.axios.post('/painel/idioma', { locale: nextLocale });
        languageOpen.value = false;
        router.reload({ preserveScroll: true });
    } finally {
        switchingLanguage.value = false;
    }
}

function onDocumentClick(event) {
    if (!languageOpen.value || !rootRef.value) return;
    if (!rootRef.value.contains(event.target)) {
        languageOpen.value = false;
    }
}

onMounted(() => document.addEventListener('click', onDocumentClick));
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick));
</script>

<template>
    <div ref="rootRef" v-bind="attrs" class="flex shrink-0 items-center gap-2">
        <div v-if="showLanguage" class="relative">
            <button
                type="button"
                class="pn-iconbtn pn-keep-shape"
                :style="btnStyle"
                :aria-label="t('header.language', 'Idioma')"
                :title="currentLanguageName"
                @click.stop="languageOpen = !languageOpen"
            >
                <LocaleFlag :locale="locale" :size="size === 'md' ? 'md' : 'sm'" />
            </button>
            <div v-if="languageOpen" class="pn-popover absolute right-0 z-[100002] mt-2 min-w-[200px] p-1.5">
                <button
                    v-for="lang in availableLanguages"
                    :key="lang.code"
                    type="button"
                    class="pn-popover__item"
                    :class="lang.code === locale ? 'pn-popover__item--active' : ''"
                    @click="switchLanguage(lang.code)"
                >
                    <span class="flex min-w-0 items-center gap-2.5">
                        <LocaleFlag :locale="lang.code" size="sm" />
                        <span class="truncate">{{ lang.name }}</span>
                    </span>
                    <Check v-if="lang.code === locale" class="h-4 w-4 shrink-0" weight="fill" />
                </button>
            </div>
        </div>
        <ThemeToggler v-if="showTheme && showToggler" :size="size" />
    </div>
</template>
