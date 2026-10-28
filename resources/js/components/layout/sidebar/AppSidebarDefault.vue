<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { PanelRightOpen, X } from 'lucide-vue-next';
import { useSidebar } from '@/composables/useSidebar';
import ConquistasWidget from '@/components/layout/ConquistasWidget.vue';
import AppSidebarNavList from '@/components/layout/sidebar/AppSidebarNavList.vue';
import SidebarGroupHeader from '@/components/layout/sidebar/SidebarGroupHeader.vue';
import PnWordmark from '@/components/paynext/PnWordmark.vue';
import { useAppSidebarNav, navPrefetch } from '@/composables/useAppSidebarNav';
import { useNavGroups } from '@/composables/useNavGroups';

const { isExpanded, isMobileOpen, toggleSidebar, isMobile, closeMobileSidebarIfOpen } = useSidebar();
const { page, homeHref, navItems, navGroups, isActive } = useAppSidebarNav();
const groupState = useNavGroups('pn-nav-groups:seller');

const showText = () => isExpanded.value || isMobileOpen.value;

const user = computed(() => page.props.auth?.user ?? null);
const userInitials = computed(() =>
    String(user.value?.name || '?')
        .trim()
        .split(/\s+/)
        .slice(0, 2)
        .map((p) => p.charAt(0).toUpperCase())
        .join(''),
);
const userRole = computed(() => (page.props.customer_panel ? 'Área do cliente' : 'Infoprodutor'));

const hoverLabel = ref('');
const hoverX = ref(0);
const hoverY = ref(0);

function onItemMouseEnter(event, label) {
    if (showText() || isMobile.value) return;
    hoverLabel.value = label;
    hoverX.value = event.clientX + 14;
    hoverY.value = event.clientY;
}

function onItemMouseMove(event) {
    if (!hoverLabel.value) return;
    hoverX.value = event.clientX + 14;
    hoverY.value = event.clientY;
}

function onItemMouseLeave() {
    hoverLabel.value = '';
}
</script>

<template>
    <aside
        :class="[
            'pn-app-sidebar pn-glass pn-glass--strong fixed z-[45] flex flex-col gap-1 transition-[width,transform] duration-300 ease-[cubic-bezier(.16,1,.3,1)]',
            'inset-y-0 left-0 rounded-none rounded-r-[28px] lg:inset-y-4 lg:left-4 lg:rounded-[36px]',
            {
                'w-[280px] translate-x-0': isMobileOpen,
                '-translate-x-full': !isMobileOpen,
                'pointer-events-none': isMobile && !isMobileOpen,
                'lg:pointer-events-auto lg:translate-x-0': true,
                'lg:w-[264px]': isExpanded || isMobileOpen,
                'lg:w-[88px]': !isExpanded && !isMobileOpen,
            },
        ]"
    >
        <div :class="['flex items-center px-5 pb-3 pt-6', showText() ? 'justify-between gap-2' : 'lg:justify-center lg:px-0']">
            <Link
                v-if="showText()"
                :href="homeHref"
                :prefetch="navPrefetch(isMobile)"
                class="flex min-w-0 flex-1 items-center no-underline hover:no-underline"
                @click="closeMobileSidebarIfOpen"
            >
                <PnWordmark />
            </Link>
            <button
                v-if="showText()"
                type="button"
                class="pn-iconbtn pn-keep-shape shrink-0"
                style="width: 38px; height: 38px"
                :aria-label="isMobile ? 'Fechar menu' : 'Recolher menu'"
                @click="toggleSidebar"
            >
                <X v-if="isMobile" class="h-[18px] w-[18px]" />
                <PanelRightOpen v-else class="h-[18px] w-[18px]" />
            </button>
            <button v-else type="button" class="pn-keep-shape rounded-full" aria-label="Expandir menu" @click="toggleSidebar">
                <PnWordmark variant="icon" />
            </button>
        </div>

        <nav class="no-scrollbar flex-1 overflow-y-auto overflow-x-visible px-3 py-2" :class="showText() ? '' : 'lg:px-[18px]'">
            <!-- Menu aberto: grupos com título, recolhíveis. Menu em ícones: lista plana. -->
            <template v-if="showText()">
                <section v-for="group in navGroups" :key="group.id" :class="group.label ? 'mt-1' : ''">
                    <SidebarGroupHeader
                        v-if="group.label"
                        :label="group.label"
                        :open="groupState.isOpen(group, isActive)"
                        :count="group.items.length"
                        :has-active="groupState.hasActive(group, isActive)"
                        :controls="`nav-${group.id}`"
                        @toggle="groupState.toggle(group, isActive)"
                    />
                    <div v-show="groupState.isOpen(group, isActive)" :id="`nav-${group.id}`">
                        <AppSidebarNavList :items="group.items" :show-text="true" :is-active="isActive" :is-mobile="isMobile" />
                    </div>
                </section>
                <AppSidebarNavList v-if="isMobile && !page.props.customer_panel" class="mt-2" :items="[{ pwaInstall: true }]" :show-text="true" :is-active="isActive" :is-mobile="isMobile" />
            </template>
            <AppSidebarNavList
                v-else
                :items="navItems"
                :show-text="false"
                :is-active="isActive"
                :is-mobile="isMobile"
                @item-mouseenter="onItemMouseEnter"
                @item-mousemove="onItemMouseMove"
                @item-mouseleave="onItemMouseLeave"
            />
        </nav>

        <div v-if="isMobile && showText() && !page.props.customer_panel" class="px-4 lg:hidden">
            <ConquistasWidget variant="sidebar" />
        </div>

        <div
            v-if="user"
            class="mx-3 mb-4 mt-1 flex items-center gap-2.5 border-t pt-3"
            :class="showText() ? 'px-1.5' : 'lg:justify-center lg:px-0'"
            style="border-color: var(--border-default)"
        >
            <span class="pn-avatar pn-avatar--md">{{ userInitials }}</span>
            <span v-if="showText()" class="min-w-0">
                <b class="block truncate text-[13px] font-normal" style="color: var(--text-primary)">{{ user.name }}</b>
                <small class="block truncate text-[11px] font-light" style="color: var(--text-tertiary)">{{ userRole }}</small>
            </span>
        </div>
    </aside>
    <div
        v-if="hoverLabel"
        class="pointer-events-none fixed z-[100001] hidden -translate-y-1/2 whitespace-nowrap rounded-full px-3 py-1.5 text-xs lg:block"
        style="background: rgba(12, 14, 15, 0.9); color: #f4f6f7; box-shadow: var(--shadow-lg); backdrop-filter: var(--glass-blur)"
        :style="{ left: `${hoverX}px`, top: `${hoverY}px` }"
    >
        {{ hoverLabel }}
    </div>
</template>

<style scoped>
/* .pn-glass define position/raio/transição; a sidebar flutuante precisa dos seus. */
.pn-app-sidebar {
    position: fixed;
    border-radius: 0 28px 28px 0;
    box-shadow: var(--shadow-md);
    transition:
        width var(--dur-base) var(--ease-out),
        transform var(--dur-base) var(--ease-out);
}
@media (min-width: 1024px) {
    .pn-app-sidebar {
        border-radius: var(--radius-2xl);
    }
}
</style>
