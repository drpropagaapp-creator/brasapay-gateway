<script setup>
import { computed, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useSidebarProvider } from '@/composables/useSidebar';
import { useThemedPageHeading } from '@/composables/useThemedPageHeading';
import PlatformSidebar from '@/components/layout/PlatformSidebar.vue';
import PlatformAppHeader from '@/components/layout/PlatformAppHeader.vue';
import DemoModeBanner from '@/components/layout/DemoModeBanner.vue';
import TotpPromptBanner from '@/components/layout/TotpPromptBanner.vue';
import Backdrop from '@/components/layout/Backdrop.vue';
import FlashToast from '@/components/layout/FlashToast.vue';
import PnAurora from '@/components/paynext/PnAurora.vue';

const { isExpanded } = useSidebarProvider();
const page = usePage();
const { clearHeading } = useThemedPageHeading();
watch(() => page.url, () => clearHeading());
const contentMaxWidth = computed(() => (page.props.layoutFullWidth ? 'max-w-[1600px]' : 'max-w-7xl'));
const layoutContentFlushLeft = computed(() => !!page.props.layoutContentFlushLeft);
</script>

<template>
    <div class="pn-shell platform-shell min-h-screen">
        <PnAurora />
        <PlatformSidebar />
        <Backdrop />
        <div
            class="flex min-h-screen flex-col px-3 pt-3 transition-[padding] duration-300 ease-[cubic-bezier(.16,1,.3,1)] md:px-5 md:pt-5 lg:pr-8 lg:pt-6"
            :class="[isExpanded ? 'lg:pl-[296px]' : 'lg:pl-[120px]']"
        >
            <div class="flex w-full shrink-0 flex-col gap-2">
                <DemoModeBanner />
                <TotpPromptBanner />
                <PlatformAppHeader />
            </div>
            <FlashToast />
            <div class="flex min-h-0 flex-1 flex-col">
                <main class="min-w-0 flex-1 overflow-x-hidden px-1 pb-12 pt-2 md:pt-3 lg:pb-10">
                    <div
                        class="w-full min-w-0"
                        :class="[layoutContentFlushLeft ? 'max-w-none lg:-ml-6' : 'mx-auto', !layoutContentFlushLeft && contentMaxWidth]"
                    >
                        <slot />
                    </div>
                </main>
            </div>
        </div>
    </div>
</template>
