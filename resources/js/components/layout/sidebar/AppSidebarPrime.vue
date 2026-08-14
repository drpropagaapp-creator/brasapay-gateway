<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { X, PanelLeftClose, PanelLeftOpen } from 'lucide-vue-next';
import { useSidebar } from '@/composables/useSidebar';
import ConquistasWidget from '@/components/layout/ConquistasWidget.vue';
import AppSidebarNavList from '@/components/layout/sidebar/AppSidebarNavList.vue';
import { useAppSidebarNav, panelNavPrefetch } from '@/composables/useAppSidebarNav';

const { isExpanded, isMobileOpen, toggleSidebar, isMobile } = useSidebar();
const {
    page,
    homeHref,
    appSettings,
    appName,
    hasLogoFull,
    navItems,
    isActive,
} = useAppSidebarNav();

// Em mobile a sidebar abre sempre completa; o modo rail só existe no desktop.
const showText = computed(() => isMobile.value || isExpanded.value);

const appInitial = computed(() => {
    const name = String(appName() ?? '').trim();
    return name ? name.charAt(0).toUpperCase() : 'B';
});
</script>

<template>
    <aside
        :class="[
            'prime-sidebar fixed left-0 top-0 z-[99999] flex h-screen flex-col',
            'transition-transform duration-300 ease-in-out',
            isMobileOpen ? 'translate-x-0' : '-translate-x-full',
            isMobile && !isMobileOpen ? 'pointer-events-none' : '',
            'lg:translate-x-0',
            !showText ? 'prime-sidebar--rail' : '',
        ]"
    >
        <div
            class="flex h-[72px] shrink-0 items-center"
            :class="showText ? 'justify-between px-5' : 'justify-center px-2'"
        >
            <Link
                :href="homeHref"
                :prefetch="panelNavPrefetch"
                class="flex min-w-0 items-center gap-3 overflow-hidden"
                :class="showText ? 'flex-1' : ''"
                :title="showText ? '' : appName()"
            >
                <template v-if="showText && hasLogoFull()">
                    <img
                        v-if="appSettings().app_logo"
                        :src="appSettings().app_logo"
                        :alt="appName()"
                        class="h-8 max-w-[170px] object-contain object-left dark:hidden"
                    />
                    <img
                        v-if="appSettings().app_logo_dark"
                        :src="appSettings().app_logo_dark"
                        :alt="appName()"
                        class="hidden h-8 max-w-[170px] object-contain object-left dark:block"
                    />
                    <img
                        v-else-if="appSettings().app_logo && !appSettings().app_logo_dark"
                        :src="appSettings().app_logo"
                        :alt="appName()"
                        class="hidden h-8 max-w-[170px] object-contain object-left dark:block"
                    />
                </template>
                <span
                    v-else-if="showText"
                    class="prime-fg truncate text-[15px] font-semibold tracking-tight"
                >
                    {{ appName() }}
                </span>
                <span
                    v-else
                    class="prime-icon-circle h-9 w-9 text-sm font-bold"
                    aria-hidden="true"
                >
                    {{ appInitial }}
                </span>
            </Link>
            <button
                v-if="isMobile"
                type="button"
                class="prime-icon-btn ml-1 flex h-8 w-8 shrink-0 touch-manipulation cursor-pointer select-none items-center justify-center rounded-md"
                aria-label="Fechar menu"
                @click="toggleSidebar"
            >
                <X class="h-4 w-4" aria-hidden="true" />
            </button>
        </div>

        <nav class="flex-1 overflow-y-auto no-scrollbar px-3 py-4">
            <AppSidebarNavList
                :items="navItems"
                :show-text="showText"
                :is-active="isActive"
                :is-mobile="isMobile"
                variant="prime"
            />
        </nav>

        <div class="prime-sidebar-footer shrink-0 space-y-3 px-3 py-3">
            <ConquistasWidget
                v-if="showText && !page.props.customer_panel"
                variant="sidebar"
            />
            <button
                v-if="!isMobile"
                type="button"
                class="prime-collapse-btn"
                :aria-label="isExpanded ? 'Recolher menu' : 'Expandir menu'"
                :title="isExpanded ? 'Recolher menu' : 'Expandir menu'"
                @click="toggleSidebar"
            >
                <PanelLeftClose v-if="isExpanded" class="h-4 w-4 shrink-0" aria-hidden="true" />
                <PanelLeftOpen v-else class="h-4 w-4 shrink-0" aria-hidden="true" />
                <span v-if="isExpanded">Recolher</span>
            </button>
        </div>
    </aside>
</template>
