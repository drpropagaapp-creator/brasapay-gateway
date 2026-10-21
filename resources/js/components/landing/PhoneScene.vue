<script setup>
import { gsap } from 'gsap';
import { ScrollTrigger } from 'gsap/ScrollTrigger';
import { onBeforeUnmount, onMounted, ref } from 'vue';

gsap.registerPlugin(ScrollTrigger);

/**
 * Cena pinada: o celular chega inclinado e assenta; as notificações de venda
 * caem na tela com mola, o brilho pulsa a cada "cha-ching" e o saldo sobe.
 * No desktop o scroll dirige a cena (scrub); no mobile ela toca ao entrar.
 */
const scene = ref(null);
let mm = null;

function buildTimeline(scrubbed) {
    const q = gsap.utils.selector(scene.value);
    const tl = gsap.timeline(
        scrubbed
            ? {
                  scrollTrigger: {
                      trigger: scene.value,
                      start: 'top top',
                      end: '+=260%',
                      pin: true,
                      scrub: 1,
                      anticipatePin: 1,
                  },
              }
            : {
                  scrollTrigger: {
                      trigger: scene.value,
                      start: 'top 65%',
                      toggleActions: 'play none none none',
                  },
                  defaults: { duration: 0.8 },
              }
    );

    // Estados iniciais (via JS: sem JS a cena fica visível e estática).
    gsap.set(q('.ph-device'), { rotateY: -22, rotateX: 8, y: 90, scale: 0.92, transformPerspective: 1400 });
    gsap.set(q('.ph-notif'), { yPercent: -160, opacity: 0, scale: 0.92 });
    gsap.set(q('.ph-balance'), { yPercent: 140, opacity: 0 });
    gsap.set(q('.ph-glow'), { opacity: 0.25, scale: 0.9 });
    gsap.set(q('.ph-line').slice(1), { yPercent: 40, opacity: 0 });
    gsap.set(q('.ph-chip'), { scale: 0, opacity: 0 });

    tl.to(q('.ph-device'), { rotateY: 0, rotateX: 0, y: 0, scale: 1, duration: 1.2, ease: 'power2.out' })

        // Notificação 1 cai.
        .to(q('.ph-notif')[0], { yPercent: 0, opacity: 1, scale: 1, duration: 0.6, ease: 'back.out(1.6)' }, 1.15)
        .to(q('.ph-glow'), { opacity: 0.85, scale: 1.12, duration: 0.3, ease: 'power2.out' }, 1.2)
        .to(q('.ph-glow'), { opacity: 0.4, scale: 1, duration: 0.5 }, 1.5)
        .to(q('.ph-chip'), { scale: 1, opacity: 1, duration: 0.45, ease: 'back.out(2.2)' }, 1.35)

        // Troca da frase lateral.
        .to(q('.ph-line')[0], { yPercent: -40, opacity: 0, duration: 0.35 }, 2.0)
        .to(q('.ph-line')[1], { yPercent: 0, opacity: 1, duration: 0.45, ease: 'power3.out' }, 2.2)

        // Notificação 2 empurra a primeira.
        .to(q('.ph-notif')[0], { y: 86, scale: 0.96, filter: 'brightness(0.8)', duration: 0.5 }, 2.6)
        .to(q('.ph-notif')[1], { yPercent: 0, opacity: 1, scale: 1, duration: 0.6, ease: 'back.out(1.6)' }, 2.65)
        .to(q('.ph-glow'), { opacity: 0.9, scale: 1.14, duration: 0.3 }, 2.7)
        .to(q('.ph-glow'), { opacity: 0.45, scale: 1, duration: 0.5 }, 3.0)

        // Saldo consolida.
        .to(q('.ph-line')[1], { yPercent: -40, opacity: 0, duration: 0.35 }, 3.4)
        .to(q('.ph-line')[2], { yPercent: 0, opacity: 1, duration: 0.45, ease: 'power3.out' }, 3.6)
        .to(q('.ph-balance'), { yPercent: 0, opacity: 1, duration: 0.6, ease: 'power3.out' }, 3.55)
        .to(q('.ph-device'), { rotateY: 6, duration: 1.0, ease: 'power1.inOut' }, 3.6);

    return tl;
}

// Relógio e data reais do visitante, como na tela de bloqueio de verdade.
const clock = ref('');
const today = ref('');
let clockTimer = 0;

function tickClock() {
    const now = new Date();
    clock.value = now.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' });
    today.value = now.toLocaleDateString('pt-BR', { weekday: 'long', day: 'numeric', month: 'long' });
}

tickClock();

onMounted(() => {
    tickClock();
    clockTimer = setInterval(tickClock, 1000);

    mm = gsap.matchMedia();
    mm.add(
        {
            desktop: '(min-width: 900px)',
            mobile: '(max-width: 899px)',
        },
        (ctx) => {
            const tl = buildTimeline(Boolean(ctx.conditions.desktop));
            return () => tl.scrollTrigger?.kill();
        }
    );
});

onBeforeUnmount(() => {
    clearInterval(clockTimer);
    mm?.revert();
});
</script>

<template>
    <section ref="scene" class="ph" aria-label="Notificação de venda no celular">
        <div class="lp-wrap ph-grid">
            <div class="ph-copy">
                <p class="lp-eyebrow">SEU NEGÓCIO NA PALMA DA MÃO</p>
                <h2 class="sr-only">Suas vendas acontecem. Você acompanha tudo.</h2>
                <div class="ph-lines" aria-hidden="true">
                    <p class="lp-h2 ph-line">Suas vendas<br />acontecem.</p>
                    <p class="lp-h2 ph-line">Você acompanha<br /><span class="lp-grad">tudo.</span></p>
                    <p class="lp-h2 ph-line">Mais clareza<br /><span class="lp-grad">para decidir.</span></p>
                </div>
                <p class="lp-sub-sm">
                    Chega de perder tempo tentando entender seus próprios números. Tenha uma visão mais
                    clara das suas operações, acompanhe o desempenho do seu negócio e tome decisões com
                    mais segurança.
                </p>
                <p class="lp-strong">Mais clareza para decidir. Mais liberdade para crescer.</p>
            </div>

            <div class="ph-stage">
                <div class="ph-glow" aria-hidden="true"></div>
                <div class="ph-device">
                    <div class="ph-frame">
                        <span class="ph-notch" aria-hidden="true"></span>
                        <div class="ph-screen">
                            <p class="ph-clock">{{ clock }}</p>
                            <p class="ph-date">{{ today }}</p>

                            <div class="ph-notif ph-n1">
                                <span class="ph-notif-icon"><img src="/images/brasapay/simbolo.png" alt="" /></span>
                                <span class="ph-notif-body">
                                    <strong>BrasaPay</strong>
                                    <span>Venda aprovada · <b>R$&nbsp;497,00</b></span>
                                </span>
                                <span class="ph-notif-time">agora</span>
                            </div>

                            <div class="ph-notif ph-n2">
                                <span class="ph-notif-icon"><img src="/images/brasapay/simbolo.png" alt="" /></span>
                                <span class="ph-notif-body">
                                    <strong>BrasaPay</strong>
                                    <span>PIX recebido · <b>R$&nbsp;197,00</b></span>
                                </span>
                                <span class="ph-notif-time">agora</span>
                            </div>

                            <span class="ph-chip" aria-hidden="true">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M11 5 6 9H3v6h3l5 4V5z" />
                                    <path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 5.5a9 9 0 0 1 0 13" />
                                </svg>
                                cha-ching!
                            </span>

                            <div class="ph-balance">
                                <span>Saldo disponível</span>
                                <strong>R$ 12.480,90</strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>
</template>

<style scoped>
.ph {
    min-height: 100svh;
    display: flex;
    align-items: center;
    overflow: hidden;
}
.ph-grid {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
    gap: clamp(2rem, 6vw, 5rem);
    align-items: center;
    width: 100%;
}
@media (max-width: 899px) {
    .ph { padding: 4rem 0; min-height: auto; }
    .ph-grid { grid-template-columns: 1fr; }
}
.ph-lines {
    position: relative;
    min-height: 7.2em;
}
.ph-line {
    position: absolute;
    inset: 0 auto auto 0;
    margin: 0;
}
/* Sem JS / movimento reduzido: só a primeira frase aparece. */
.ph-line:not(:first-child) { opacity: 0; }
.ph-stage {
    position: relative;
    display: grid;
    place-items: center;
    min-height: 560px;
}
@media (max-width: 899px) {
    .ph-stage { min-height: 480px; }
}
.ph-glow {
    position: absolute;
    width: 420px;
    height: 420px;
    border-radius: 50%;
    background: radial-gradient(circle, rgba(10, 152, 110, 0.4), rgba(92, 181, 54, 0.08) 55%, transparent 72%);
    filter: blur(34px);
}
.ph-device {
    position: relative;
    will-change: transform;
}
.ph-frame {
    width: min(290px, 74vw);
    aspect-ratio: 290 / 590;
    border-radius: 44px;
    padding: 10px;
    background: linear-gradient(160deg, #2a3335, #0b1315 60%, #222b2c);
    border: 1px solid rgba(140, 195, 200, 0.22);
    box-shadow:
        0 60px 120px -40px rgba(0, 0, 0, 0.9),
        inset 0 1px 0 rgba(255, 255, 255, 0.12);
}
.ph-notch {
    position: absolute;
    top: 22px;
    left: 50%;
    transform: translateX(-50%);
    width: 86px;
    height: 24px;
    border-radius: 14px;
    background: #050807;
    z-index: 3;
}
.ph-screen {
    position: relative;
    height: 100%;
    border-radius: 35px;
    overflow: hidden;
    background:
        radial-gradient(90% 60% at 80% 0%, rgba(10, 152, 110, 0.22), transparent 60%),
        radial-gradient(80% 60% at 10% 100%, rgba(92, 181, 54, 0.12), transparent 60%),
        linear-gradient(170deg, #0a1517 0%, #04090b 100%);
    padding: 64px 12px 16px;
    display: flex;
    flex-direction: column;
    align-items: center;
}
.ph-clock {
    margin: 10px 0 0;
    font-size: 2.9rem;
    font-weight: 500;
    letter-spacing: 0.01em;
    color: #eef6ef;
}
.ph-date {
    margin: 0.1rem 0 1.2rem;
    font-size: 0.72rem;
    color: rgba(238, 246, 239, 0.6);
}
.ph-date::first-letter {
    text-transform: uppercase;
}
.ph-clock {
    font-variant-numeric: tabular-nums;
}
.ph-notif {
    position: absolute;
    top: 170px;
    left: 10px;
    right: 10px;
    display: flex;
    align-items: center;
    gap: 0.6rem;
    background: rgba(22, 34, 36, 0.86);
    border: 1px solid rgba(140, 195, 200, 0.2);
    backdrop-filter: blur(14px);
    border-radius: 16px;
    padding: 0.65rem 0.75rem;
    box-shadow: 0 16px 34px -16px rgba(0, 0, 0, 0.7);
    will-change: transform;
}
.ph-n2 { z-index: 2; }
.ph-notif-icon {
    flex: none;
    width: 36px;
    height: 36px;
    border-radius: 9px;
    display: grid;
    place-items: center;
    background: linear-gradient(160deg, #0f1d20, #061012);
    border: 1px solid rgba(0, 136, 148, 0.35);
    box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.06);
}
.ph-notif-icon img {
    width: 24px;
    height: 24px;
    object-fit: contain;
}
.ph-notif-body {
    display: grid;
    line-height: 1.25;
    text-align: left;
}
.ph-notif-body strong {
    font-size: 0.68rem;
    font-weight: 600;
    color: rgba(238, 246, 239, 0.75);
    letter-spacing: 0.04em;
}
.ph-notif-body span {
    font-size: 0.8rem;
    color: #eef6ef;
}
.ph-notif-body b {
    white-space: nowrap;
    font-weight: 600;
    color: #79e0c8;
}
.ph-notif-time {
    margin-left: auto;
    font-size: 0.62rem;
    color: rgba(238, 246, 239, 0.45);
    align-self: flex-start;
}
.ph-chip {
    position: absolute;
    top: 136px;
    right: 16px;
    font-size: 0.68rem;
    font-weight: 500;
    padding: 0.3rem 0.6rem;
    border-radius: 999px;
    color: #f4fcfa;
    background: linear-gradient(95deg, #5cb536, #0a986e);
    box-shadow: 0 8px 22px -8px rgba(10, 152, 110, 0.8);
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
}
.ph-chip svg {
    width: 12px;
    height: 12px;
}
.ph-balance {
    position: absolute;
    left: 10px;
    right: 10px;
    bottom: 14px;
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    padding: 0.8rem 0.95rem;
    border-radius: 16px;
    background: rgba(11, 20, 22, 0.92);
    border: 1px solid rgba(10, 152, 110, 0.35);
}
.ph-balance span {
    font-size: 0.68rem;
    color: rgba(238, 246, 239, 0.6);
}
.ph-balance strong {
    font-size: 1.02rem;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    background: linear-gradient(95deg, #5cb536, #0a986e);
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
}
</style>
