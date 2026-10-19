<script setup>
import { computed, ref } from 'vue';
import { QrCode, ShieldCheck } from 'lucide-vue-next';
import CookieConsentBanner from '@/components/legal/CookieConsentBanner.vue';
import { useAuthBranding } from '@/composables/useAuthBranding';
import PnAurora from '@/components/paynext/PnAurora.vue';
import PnWordmark from '@/components/paynext/PnWordmark.vue';
import PnGlassCard from '@/components/paynext/PnGlassCard.vue';
import PnAnimatedNumber from '@/components/paynext/PnAnimatedNumber.vue';
import PnBarChart from '@/components/paynext/PnBarChart.vue';
import PnMeter from '@/components/paynext/PnMeter.vue';

/**
 * Login imersivo do Paynext DS: painel de vidro com o formulário à esquerda; à direita,
 * aurora com brilho seguindo o cursor e cartões de vidro flutuando em paralaxe (ilustração).
 */
const props = defineProps({
    title: { type: String, default: '' },
    subtitle: { type: String, default: '' },
    variant: { type: String, default: 'seller' },
    wide: { type: Boolean, default: false },
});

const { primary, heroTagline, heroSubtagline } = useAuthBranding();

const root = ref(null);
const mouse = ref({ x: 0, y: 0 });
function onMove(e) {
    const el = root.value;
    if (!el) return;
    const r = el.getBoundingClientRect();
    mouse.value = { x: (e.clientX - r.left) / r.width - 0.5, y: (e.clientY - r.top) / r.height - 0.5 };
    el.style.setProperty('--cx', `${e.clientX - r.left}px`);
    el.style.setProperty('--cy', `${e.clientY - r.top}px`);
}
const par = (d) => ({
    transform: `translate3d(${mouse.value.x * d}px, ${mouse.value.y * d}px, 0) rotateX(${-mouse.value.y * d * 0.15}deg) rotateY(${mouse.value.x * d * 0.2}deg)`,
});

/* "Bem-vindo de volta." — o ponto final do título vira lime */
const titleParts = computed(() => {
    const t = String(props.title || '').trim();
    return t.endsWith('.') ? [t.slice(0, -1), true] : [t, false];
});
/* Frase de destaque (configurável no admin): última palavra em lime, ponto final lime */
const heroParts = computed(() => {
    const t = String(heroTagline.value || '').trim().replace(/\.$/, '');
    const i = t.lastIndexOf(' ');
    return i > 0 ? [t.slice(0, i + 1), t.slice(i + 1)] : ['', t];
});
</script>

<template>
    <div ref="root" class="wl-root pn-auth" :class="wide ? 'pn-auth--wide' : ''" @mousemove="onMove">
        <PnAurora />
        <i class="pn-auth__glow" aria-hidden="true" />

        <div class="pn-auth__panel-wrap">
            <PnGlassCard variant="strong" animate class="pn-auth__panel">
                <PnWordmark size="md" />
                <div class="pn-auth__body">
                    <span class="pn-badge pn-badge--brand pn-badge--nodot mb-5 self-start">
                        {{ variant === 'platform' ? 'Painel da plataforma' : 'Gateway de pagamentos' }}
                    </span>
                    <h1 v-if="title" class="pn-auth__title">
                        {{ titleParts[0] }}<span class="pn-dot">.</span>
                    </h1>
                    <p v-if="subtitle" class="pn-auth__subtitle">{{ subtitle }}</p>
                    <div class="pn-auth__form">
                        <slot />
                    </div>
                </div>
                <div v-if="$slots.footer" class="pn-auth__footer">
                    <slot name="footer" />
                </div>
            </PnGlassCard>
        </div>

        <div class="pn-auth__hero" aria-hidden="true">
            <div class="pn-auth__hero-copy">
                <p class="pn-eyebrow">{{ heroSubtagline }}</p>
                <h2>{{ heroParts[0] }}<span class="pn-auth__hero-accent">{{ heroParts[1] }}</span><span class="pn-dot">.</span></h2>
            </div>
            <div class="pn-auth__card pn-auth__card--volume" :style="par(24)">
                <PnGlassCard variant="strong" :spotlight="false" class="pn-auth__float" style="padding: 26px">
                    <div class="text-xs font-light" style="color: var(--text-secondary)">Volume hoje</div>
                    <div class="pn-auth__number"><sup>R$</sup><PnAnimatedNumber :value="184320" :decimals="2" /></div>
                    <PnBarChart :data="[30, 44, 36, 58, 48, 66, 54, 78, 62, 100]" :height="90" />
                </PnGlassCard>
            </div>
            <div class="pn-auth__card pn-auth__card--rate" :style="par(42)">
                <PnGlassCard variant="lime" :spotlight="false" class="pn-auth__float pn-auth__float--rev" style="padding: 20px; box-shadow: 0 0 60px rgba(225, 251, 77, 0.35)">
                    <div class="flex justify-between text-xs"><span>Taxa de aprovação</span><ShieldCheck class="h-5 w-5" weight="fill" /></div>
                    <div class="pn-auth__number" style="margin: 2px 0 10px"><PnAnimatedNumber :value="98.4" :decimals="1" suffix="%" /></div>
                    <PnMeter :value="98" :show-marker="false" style="background: rgba(21, 23, 24, 0.15)" />
                </PnGlassCard>
            </div>
            <div class="pn-auth__card pn-auth__card--pix" :style="par(30)">
                <PnGlassCard variant="contrast" :spotlight="false" class="pn-auth__float pn-auth__pill">
                    <span class="pn-auth__pill-icon"><QrCode class="h-5 w-5" weight="fill" /></span>
                    <span class="flex-1"><b class="block text-[13.5px] font-normal">Pix aprovado</b><span class="text-[11px] font-light" style="color: var(--text-tertiary)">agora</span></span>
                    <b class="font-[family-name:var(--font-display)] text-sm font-normal">R$ 3.299,00</b>
                </PnGlassCard>
            </div>
        </div>

        <CookieConsentBanner />
    </div>
</template>

<style scoped>
.wl-root {
    --wl-primary: v-bind(primary);
}
.pn-auth {
    position: relative;
    min-height: 100vh;
    overflow: hidden;
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    perspective: 1400px;
}
@media (min-width: 1024px) {
    .pn-auth {
        height: 100vh;
        grid-template-columns: minmax(440px, 540px) 1fr;
    }
    .pn-auth--wide {
        grid-template-columns: minmax(520px, 660px) 1fr;
    }
}
.pn-auth__glow {
    position: absolute;
    left: var(--cx, 50%);
    top: var(--cy, 50%);
    width: 420px;
    height: 420px;
    margin: -210px 0 0 -210px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(225, 251, 77, 0.18), transparent 65%);
    pointer-events: none;
    transition:
        left 0.25s,
        top 0.25s;
}
.pn-auth__panel-wrap {
    position: relative;
    display: flex;
    padding: 16px;
}
@media (min-width: 1024px) {
    .pn-auth__panel-wrap {
        padding: 28px;
    }
}
.pn-auth__panel {
    flex: 1;
    display: flex;
    flex-direction: column;
    gap: 28px;
    padding: 36px 28px;
    border-radius: 40px;
    overflow-y: auto;
}
@media (min-width: 640px) {
    .pn-auth__panel {
        padding: 44px 48px;
    }
}
.pn-auth__body {
    display: flex;
    flex-direction: column;
    margin: auto 0;
}
.pn-auth__title {
    font: 400 40px/46px var(--font-display);
    letter-spacing: -0.04em;
}
@media (min-width: 640px) {
    .pn-auth__title {
        font-size: 44px;
        line-height: 50px;
    }
}
.pn-auth__subtitle {
    margin: 12px 0 0;
    font: 300 14px/22px var(--font-body);
    color: var(--text-secondary);
}
.pn-auth__form {
    margin-top: 28px;
}
.pn-auth__footer {
    font: 300 11px/16px var(--font-body);
    color: var(--text-tertiary);
}
.pn-auth__hero {
    position: relative;
    display: none;
    transform-style: preserve-3d;
}
@media (min-width: 1024px) {
    .pn-auth__hero {
        display: block;
    }
}
.pn-auth__hero-copy {
    position: absolute;
    top: 12%;
    left: 10%;
    right: 14%;
}
.pn-auth__hero-copy h2 {
    margin-top: 14px;
    font: 400 60px/66px var(--font-display);
    letter-spacing: -0.045em;
}
.pn-auth__hero-accent {
    color: var(--lime-300);
}
:global(html:not(.dark)) .pn-auth__hero-accent {
    color: var(--ink-900);
    background: linear-gradient(transparent 62%, var(--lime-300) 62%);
}
.pn-auth__card {
    position: absolute;
    transition: transform 0.5s var(--ease-out);
}
.pn-auth__card--volume {
    left: 8%;
    top: 46%;
    width: 380px;
}
.pn-auth__card--rate {
    right: 6%;
    top: 40%;
    width: 260px;
}
.pn-auth__card--pix {
    right: 10%;
    top: 76%;
    width: 320px;
}
.pn-auth__float {
    animation:
        pn-fade-up 0.9s var(--ease-out) 0.3s both,
        pn-float 7s ease-in-out 1.2s infinite;
}
.pn-auth__float--rev {
    animation:
        pn-fade-up 0.9s var(--ease-out) 0.5s both,
        pn-float 6s ease-in-out 2s infinite reverse;
}
.pn-auth__number {
    margin: 6px 0 14px;
    font: 400 38px/46px var(--font-display);
    letter-spacing: -0.03em;
}
.pn-auth__number sup {
    font-size: 16px;
    vertical-align: 0.9em;
    margin-right: 6px;
    color: var(--text-secondary);
}
.pn-auth__pill {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 14px 18px;
    border-radius: 24px;
}
.pn-auth__pill-icon {
    width: 40px;
    height: 40px;
    flex: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 50%;
    background: var(--lime-300);
    color: var(--ink-900);
}
</style>
