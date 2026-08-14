<script setup>
import AppSidebarDefault from '@/components/layout/sidebar/AppSidebarDefault.vue';
import AppSidebarAurora from '@/components/layout/sidebar/AppSidebarAurora.vue';
import AppSidebarKawaii from '@/components/layout/sidebar/AppSidebarKawaii.vue';
import AppSidebarPrime from '@/components/layout/sidebar/AppSidebarPrime.vue';
import AppSidebarStudio from '@/components/layout/sidebar/AppSidebarStudio.vue';
import { useSellerDashboardTemplate } from '@/composables/useSellerDashboardTemplate';

const { isDefault, isAurora, isKawaii, isPrime, isStudio } = useSellerDashboardTemplate();
</script>

<template>
    <AppSidebarDefault v-if="isDefault" />
    <AppSidebarAurora v-else-if="isAurora" />
    <AppSidebarKawaii v-else-if="isKawaii" />
    <AppSidebarPrime v-else-if="isPrime" />
    <AppSidebarStudio v-else-if="isStudio" />
</template>
