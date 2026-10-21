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
    gsap.set(q('.ph-line'), { yPercent: 40, opacity: 0 });
    gsap.set(q('.ph-chip'), { scale: 0, opacity: 0 });

    tl.to(q('.ph-device'), { rotateY: 0, rotateX: 0, y: 0, scale: 1, duration: 1.2, ease: 'power2.out' })
        .to(q('.ph-line')[0], { yPercent: 0, opacity: 1, duration: 0.5, ease: 'power3.out' }, 0.15)

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

onMounted(() => {
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

onBeforeUnmount(() => mm?.revert());
</script>

<template>
    <section ref="scene" class="ph" aria-label="Notificação de venda no celular">
        <div class="lp-wrap ph-grid">
            <div class="ph-copy">
                <p class="lp-eyebrow">SEU BOLSO SABE PRIMEIRO</p>
                <div class="ph-lines" aria-hidden="true">
                    <h2 class="lp-h2 ph-line">Você publica<br />a oferta.</h2>
                    <h2 class="lp-h2 ph-line">O PIX cai<br /><span class="lp-grad">em segundos.</span></h2>
                    <h2 class="lp-h2 ph-line">E o celular toca:<br /><span class="lp-grad">cha-ching.</span></h2>
                </div>
                <p class="lp-sub-sm">
                    Notificação push com som de venda realizada, direto no seu celular. Sem abrir
                    painel, sem F5 — você ouve o caixa de onde estiver.
                </p>
            </div>

            <div class="ph-stage">
                <div class="ph-glow" aria-hidden="true"></div>
                <div class="ph-device">
                    <div class="ph-frame">
                        <span class="ph-notch" aria-hidden="true"></span>
                        <div class="ph-screen">
                            <p class="ph-clock">21:47</p>
                            <p class="ph-date">quinta-feira, 12 de março</p>

                            <div class="ph-notif ph-n1">
                                <span class="ph-notif-icon">🤑</span>
                                <span class="ph-notif-body">
                                    <strong>BrasaPay</strong>
                                    <span>Venda aprovada — <b>R$ 497,00</b></span>
                                </span>
                                <span class="ph-notif-time">agora</span>
                            </div>

                            <div class="ph-notif ph-n2">
                                <span class="ph-notif-icon">💸</span>
                                <span class="ph-notif-body">
                                    <strong>BrasaPay</strong>
                                    <span>PIX recebido — <b>R$ 197,00</b></span>
                                </span>
                                <span class="ph-notif-time">agora</span>
                            </div>

                            <span class="ph-chip" aria-hidden="true">🔊 cha-ching!</span>

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
    background: radial-gradient(circle, rgba(47, 213, 127, 0.4), rgba(207, 235, 43, 0.08) 55%, transparent 72%);
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
    background: linear-gradient(160deg, #2c3330, #0d1210 60%, #232a26);
    border: 1px solid rgba(160, 200, 175, 0.22);
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
        radial-gradient(90% 60% at 80% 0%, rgba(47, 213, 127, 0.22), transparent 60%),
        radial-gradient(80% 60% at 10% 100%, rgba(207, 235, 43, 0.12), transparent 60%),
        linear-gradient(170deg, #0c1410 0%, #060a08 100%);
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
.ph-notif {
    position: absolute;
    top: 170px;
    left: 10px;
    right: 10px;
    display: flex;
    align-items: center;
    gap: 0.6rem;
    background: rgba(28, 36, 31, 0.86);
    border: 1px solid rgba(160, 200, 175, 0.2);
    backdrop-filter: blur(14px);
    border-radius: 16px;
    padding: 0.65rem 0.75rem;
    box-shadow: 0 16px 34px -16px rgba(0, 0, 0, 0.7);
    will-change: transform;
}
.ph-n2 { z-index: 2; }
.ph-notif-icon {
    width: 34px;
    height: 34px;
    border-radius: 9px;
    display: grid;
    place-items: center;
    font-size: 1.05rem;
    background: linear-gradient(135deg, rgba(207, 235, 43, 0.25), rgba(47, 213, 127, 0.3));
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
    font-weight: 600;
    color: #7ff0b4;
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
    color: #06130b;
    background: linear-gradient(95deg, #cfeb2b, #2fd57f);
    box-shadow: 0 8px 22px -8px rgba(47, 213, 127, 0.8);
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
    background: rgba(14, 20, 17, 0.92);
    border: 1px solid rgba(47, 213, 127, 0.35);
}
.ph-balance span {
    font-size: 0.68rem;
    color: rgba(238, 246, 239, 0.6);
}
.ph-balance strong {
    font-size: 1.02rem;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    background: linear-gradient(95deg, #cfeb2b, #2fd57f);
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
}
</style>
