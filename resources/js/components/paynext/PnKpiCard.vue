<script setup>
import { computed } from 'vue';
import { TrendingUp } from 'lucide-vue-next';
import PnGlassCard from '@/components/paynext/PnGlassCard.vue';
import PnAnimatedNumber from '@/components/paynext/PnAnimatedNumber.vue';

/**
 * KpiCard do Paynext DS: rótulo + ícone circular (gira e fica lime no hover),
 * valor com contagem e barras de fundo com a última em destaque.
 */
const props = defineProps({
    label: { type: String, required: true },
    value: { type: [Number, String], default: 0 },
    prefix: { type: String, default: '' },
    suffix: { type: String, default: '' },
    decimals: { type: Number, default: 0 },
    icon: { type: [Object, Function], default: null },
    delta: { type: String, default: '' },
    direction: { type: String, default: 'up' },
    caption: { type: String, default: '' },
    bars: { type: Array, default: null },
    index: { type: Number, default: 0 },
    variant: { type: String, default: 'default' },
    /** Oculta o valor (botão "ocultar valores" do dashboard) */
    masked: { type: Boolean, default: false },
});

const max = computed(() => (props.bars?.length ? Math.max(1, ...props.bars) : 1));
const isCurrency = computed(() => props.prefix.trim() === 'R$');
</script>

<template>
    <PnGlassCard animate lift :index="index" :variant="variant" class="pn-kpi" :class="bars?.length ? 'pn-kpi--bars' : ''">
        <div v-if="bars?.length" class="pn-kpi__bars" aria-hidden="true">
            <div
                v-for="(b, k) in bars"
                :key="k"
                class="pn-bar"
                :class="k === bars.length - 1 ? 'pn-bar--hot' : ''"
                :style="{ '--k': k, height: `${(b / max) * 100}%` }"
            />
        </div>
        <div class="pn-kpi__top">
            <span>{{ label }}</span>
            <span class="pn-kpi__icon"><component :is="icon" v-if="icon" class="h-5 w-5" /></span>
        </div>
        <div class="pn-kpi__value">
            <template v-if="masked">—</template>
            <template v-else-if="typeof value === 'number'">
                <sup v-if="isCurrency">R$</sup>
                <PnAnimatedNumber :value="value" :prefix="isCurrency ? '' : prefix" :suffix="suffix" :decimals="decimals" />
            </template>
            <template v-else>{{ prefix }}{{ value }}{{ suffix }}</template>
        </div>
        <div v-if="delta || caption || $slots.foot" class="pn-kpi__foot">
            <span v-if="delta" class="pn-delta" :class="`pn-delta--${direction}`">
                <TrendingUp class="h-3.5 w-3.5" :style="direction === 'down' ? 'transform:scaleY(-1)' : ''" />{{ delta }}
            </span>
            <span v-if="caption">{{ caption }}</span>
            <slot name="foot" />
        </div>
    </PnGlassCard>
</template>
