<script setup>
import PnBanner from '@/components/paynext/PnBanner.vue';
import { computed, ref } from 'vue';
import { Bell, X } from 'lucide-vue-next';
import { usePage } from '@inertiajs/vue3';
import { usePanelPushSubscribe } from '@/composables/usePanelPushSubscribe';

const page = usePage();
const pushEnabled = computed(() => !!page.props.push_enabled);

const {
    pushRegistered,
    pushNeedsResubscribe,
    swUpdateApplied,
    needsPermission,
    registerAndSubscribe,
    pushSubscribing,
    notificationPermission,
} = usePanelPushSubscribe();

const dismissed = ref(false);
const activating = ref(false);

const showSwUpdate = computed(() => swUpdateApplied.value && !dismissed.value);

const showResubscribe = computed(() => {
    if (!pushEnabled.value || dismissed.value) return false;
    if (notificationPermission.value !== 'granted') return false;
    return pushNeedsResubscribe.value || (!pushRegistered.value && !needsPermission.value);
});

const showNeedsPermission = computed(() => {
    if (!pushEnabled.value || dismissed.value) return false;
    return needsPermission.value && notificationPermission.value === 'default';
});

async function reactivate() {
    activating.value = true;
    try {
        if (typeof Notification !== 'undefined' && Notification.permission === 'default') {
            const result = await Notification.requestPermission();
            if (result !== 'granted') return;
        }
        await registerAndSubscribe();
    } catch (_) {}
    activating.value = false;
}

function dismiss() {
    dismissed.value = true;
    swUpdateApplied.value = false;
}
</script>

<template>
    <PnBanner
        v-if="showSwUpdate"
        class="mb-3"
        tone="info"
        :icon="Bell"
        title="Atualização do app aplicada — notificações revalidadas."
        dismissible
        @dismiss="dismiss"
    />
    <PnBanner
        v-else-if="showNeedsPermission || showResubscribe"
        class="mb-3"
        tone="warning"
        :icon="Bell"
        :title="showNeedsPermission
            ? 'Ative as notificações para receber avisos de vendas e mensagens da plataforma.'
            : 'Suas notificações push estão pausadas. Reative para voltar a receber vendas e avisos.'"
    >
        <template #actions>
            <button
                type="button"
                class="pn-btn pn-btn--primary pn-btn--sm pn-keep-shape"
                :disabled="activating || pushSubscribing"
                @click="reactivate"
            >
                {{ activating || pushSubscribing ? 'Aguarde...' : 'Reativar notificações' }}
            </button>
            <button type="button" class="pn-btn pn-btn--ghost pn-btn--sm pn-keep-shape" @click="dismiss">Agora não</button>
        </template>
    </PnBanner>
</template>
