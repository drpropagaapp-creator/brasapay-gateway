<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * Campo de brasas: partículas sobem devagar com cintilação, em três tons
 * (esmeralda, lime e uma pitada de âmbar — a "brasa"). Sprites pré-renderizados
 * para custo baixo; pausa fora da tela, na aba oculta e com reduzir-movimento.
 */
const props = defineProps({
    density: { type: Number, default: 70 },
});

const canvasRef = ref(null);
let ctx = null;
let raf = 0;
let running = false;
let particles = [];
let sprites = [];
let w = 0;
let h = 0;
let dpr = 1;
let last = 0;
let io = null;
const mouse = { x: -1e4, y: -1e4 };

const HUES = [
    { color: '47,213,127', weight: 0.58 }, // esmeralda
    { color: '207,235,43', weight: 0.3 }, // lime
    { color: '255,179,72', weight: 0.12 }, // âmbar (brasa)
];

function makeSprite(rgb) {
    const s = document.createElement('canvas');
    s.width = s.height = 64;
    const g = s.getContext('2d');
    const grad = g.createRadialGradient(32, 32, 0, 32, 32, 32);
    grad.addColorStop(0, `rgba(${rgb},1)`);
    grad.addColorStop(0.35, `rgba(${rgb},0.55)`);
    grad.addColorStop(1, `rgba(${rgb},0)`);
    g.fillStyle = grad;
    g.fillRect(0, 0, 64, 64);
    return s;
}

function pickSprite() {
    const r = Math.random();
    let acc = 0;
    for (let i = 0; i < HUES.length; i++) {
        acc += HUES[i].weight;
        if (r <= acc) return i;
    }
    return 0;
}

function spawn(randomY) {
    return {
        x: Math.random() * w,
        y: randomY ? Math.random() * h : h + 10,
        r: 0.9 + Math.random() * 2.3,
        vy: 14 + Math.random() * 30,
        drift: 6 + Math.random() * 16,
        phase: Math.random() * Math.PI * 2,
        flick: 0.6 + Math.random() * 2.2,
        alpha: 0.25 + Math.random() * 0.55,
        sprite: pickSprite(),
    };
}

function resize() {
    const c = canvasRef.value;
    if (!c) return;
    dpr = Math.min(window.devicePixelRatio || 1, 2);
    w = c.clientWidth;
    h = c.clientHeight;
    c.width = Math.round(w * dpr);
    c.height = Math.round(h * dpr);
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    const target = Math.round((props.density * w) / 1200);
    while (particles.length < target) particles.push(spawn(true));
    particles.length = Math.min(particles.length, target);
}

function tick(now) {
    if (!running) return;
    const dt = Math.min((now - last) / 1000, 0.05);
    last = now;
    ctx.clearRect(0, 0, w, h);
    ctx.globalCompositeOperation = 'lighter';

    for (let i = 0; i < particles.length; i++) {
        const p = particles[i];
        p.phase += dt * p.flick;
        p.y -= p.vy * dt;
        p.x += Math.sin(p.phase) * p.drift * dt;

        // Sopro suave para longe do cursor.
        const dx = p.x - mouse.x;
        const dy = p.y - mouse.y;
        const d2 = dx * dx + dy * dy;
        if (d2 < 22500) {
            const d = Math.sqrt(d2) || 1;
            const f = ((150 - d) / 150) * 46 * dt;
            p.x += (dx / d) * f;
            p.y += (dy / d) * f;
        }

        if (p.y < -12 || p.x < -14 || p.x > w + 14) {
            particles[i] = spawn(false);
            continue;
        }

        const a = p.alpha * (0.55 + 0.45 * Math.sin(p.phase * 2.3));
        const size = p.r * 7;
        ctx.globalAlpha = Math.max(a, 0.04);
        ctx.drawImage(sprites[p.sprite], p.x - size / 2, p.y - size / 2, size, size);
    }
    ctx.globalAlpha = 1;
    raf = requestAnimationFrame(tick);
}

function start() {
    if (running || !ctx) return;
    running = true;
    last = performance.now();
    raf = requestAnimationFrame(tick);
}

function stop() {
    running = false;
    cancelAnimationFrame(raf);
}

function onMouse(e) {
    const r = canvasRef.value?.getBoundingClientRect();
    if (!r) return;
    mouse.x = e.clientX - r.left;
    mouse.y = e.clientY - r.top;
}

function onVisibility() {
    if (document.hidden) stop();
    else start();
}

onMounted(() => {
    const c = canvasRef.value;
    ctx = c.getContext('2d');
    sprites = HUES.map((hue) => makeSprite(hue.color));
    resize();
    window.addEventListener('resize', resize);
    window.addEventListener('mousemove', onMouse, { passive: true });
    document.addEventListener('visibilitychange', onVisibility);
    io = new IntersectionObserver(([entry]) => (entry.isIntersecting ? start() : stop()), { threshold: 0 });
    io.observe(c);
});

onBeforeUnmount(() => {
    stop();
    io?.disconnect();
    window.removeEventListener('resize', resize);
    window.removeEventListener('mousemove', onMouse);
    document.removeEventListener('visibilitychange', onVisibility);
});
</script>

<template>
    <canvas ref="canvasRef" class="ember-canvas" aria-hidden="true"></canvas>
</template>

<style scoped>
.ember-canvas {
    position: absolute;
    inset: 0;
    width: 100%;
    height: 100%;
    display: block;
    pointer-events: none;
}
</style>
