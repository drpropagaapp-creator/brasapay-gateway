<script setup>
import { Link } from '@inertiajs/vue3';
import { navPrefetch } from '@/composables/useAppSidebarNav';
import { useSidebar } from '@/composables/useSidebar';
import PwaInstallButton from '@/components/layout/PwaInstallButton.vue';

/** Navegação no padrão Paynext: pílulas, ativo em lime com ícone fill, rail em quadrados 52px. */
const props = defineProps({
    items: { type: Array, default: () => [] },
    showText: { type: Boolean, default: true },
    isActive: { type: Function, required: true },
    isMobile: { type: Boolean, default: false },
});

const emit = defineEmits(['item-mouseenter', 'item-mousemove', 'item-mouseleave']);
const { closeMobileSidebarIfOpen } = useSidebar();

function linkClasses(href, isChild = false) {
    return [
        'pn-nav no-underline hover:no-underline',
        props.isActive(href) ? 'pn-nav--active' : '',
        isChild ? 'pn-nav--child' : '',
    ];
}
</script>

<template>
    <ul class="flex flex-col gap-1 overflow-visible">
        <template v-for="(item, index) in items" :key="item.separator ? `sep-${index}` : item.pwaInstall ? `pwa-${index}` : (item.href ?? index)">
            <li v-if="item.separator" class="px-4 py-2" aria-hidden="true">
                <hr class="border-0 border-t" style="border-color: var(--border-default)" />
            </li>
            <li v-else-if="item.pwaInstall && isMobile && showText" class="pt-1.5 lg:hidden">
                <PwaInstallButton variant="banner" />
            </li>
            <li v-else-if="!item.pwaInstall" class="overflow-visible">
                <Link
                    :href="item.href"
                    :prefetch="navPrefetch(isMobile)"
                    :title="showText ? '' : item.name"
                    :class="[linkClasses(item.href), showText ? '' : 'pn-nav--rail-lg']"
                    @click="closeMobileSidebarIfOpen"
                    @mouseenter="(e) => emit('item-mouseenter', e, item.name)"
                    @mousemove="(e) => emit('item-mousemove', e)"
                    @mouseleave="emit('item-mouseleave')"
                >
                    <component
                        :is="item.icon"
                        class="h-5 w-5 shrink-0"
                        :weight="isActive(item.href) ? 'fill' : 'duotone'"
                        aria-hidden="true"
                    />
                    <span v-if="showText" class="pn-nav__label flex min-w-0 items-center gap-2 truncate">
                        <span class="truncate">{{ item.name }}</span>
                        <span v-if="item.badge === 'pix-in-out'" class="inline-flex shrink-0 items-center gap-0.5">
                            <span class="pn-badge pn-badge--success pn-badge--nodot" style="height: 18px; padding: 0 6px; font-size: 9.5px">in</span>
                            <span class="pn-badge pn-badge--info pn-badge--nodot" style="height: 18px; padding: 0 6px; font-size: 9.5px">out</span>
                        </span>
                    </span>
                </Link>
                <ul v-if="showText && item.children?.length" class="mt-1 flex flex-col gap-0.5 pl-4">
                    <li v-for="child in item.children" :key="child.href">
                        <Link
                            :href="child.href"
                            :prefetch="navPrefetch(isMobile)"
                            :class="linkClasses(child.href, true)"
                            @click="closeMobileSidebarIfOpen"
                            @mouseenter="(e) => emit('item-mouseenter', e, child.name)"
                            @mousemove="(e) => emit('item-mousemove', e)"
                            @mouseleave="emit('item-mouseleave')"
                        >
                            <component
                                :is="child.icon"
                                class="h-4 w-4 shrink-0"
                                :weight="isActive(child.href) ? 'fill' : 'duotone'"
                                aria-hidden="true"
                            />
                            <span class="pn-nav__label truncate text-[13px]">{{ child.name }}</span>
                        </Link>
                    </li>
                </ul>
            </li>
        </template>
    </ul>
</template>

<style scoped>
.pn-nav--child {
    height: 40px;
}
@media (min-width: 1024px) {
    .pn-nav--rail-lg {
        width: 52px;
        height: 52px;
        padding: 0;
        justify-content: center;
        border-radius: 18px;
    }
}
</style>
