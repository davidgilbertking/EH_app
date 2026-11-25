<script setup>
// Light falling snow under the header (New Year season, while Action plays).
import { onBeforeUnmount, onMounted, ref } from 'vue';

const canvasEl = ref(null);
let ctx = null;
let flakes = [];
let raf = null;
let lastTs = 0;
let width = 0;
let height = 0;

function resize() {
    const canvas = canvasEl.value;
    if (!canvas) return;
    const dpr = Math.min(window.devicePixelRatio || 1, 2);
    width = canvas.clientWidth;
    height = canvas.clientHeight;
    canvas.width = Math.round(width * dpr);
    canvas.height = Math.round(height * dpr);
    ctx = canvas.getContext('2d');
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
    const target = Math.round(Math.min(90, Math.max(24, (width * height) / 22000)));
    while (flakes.length < target) flakes.push(makeFlake(true));
    flakes.length = target;
}

function makeFlake(anywhere) {
    const r = 3.5 + Math.random() * 5;
    return {
        x: Math.random() * width,
        y: anywhere ? Math.random() * height : -r * 2,
        r,
        speed: 12 + Math.random() * 46, // px/s, independent of size
        drift: 4 + Math.random() * 18,
        phase: Math.random() * Math.PI * 2,
        alpha: 0.4 + Math.random() * 0.5,
        angle: Math.random() * Math.PI,
        turn: (Math.random() - 0.5) * 0.6,
        shape: Math.floor(Math.random() * 3),
    };
}

function drawFlake(f) {
    ctx.save();
    ctx.translate(f.x, f.y);
    ctx.rotate(f.angle);
    ctx.globalAlpha = f.alpha;
    ctx.strokeStyle = '#fff';
    ctx.lineWidth = f.r < 5 ? 0.7 : 0.9;
    ctx.lineCap = 'round';
    if (f.shape === 2) {
        ctx.beginPath();
        for (let corner = 0; corner < 6; corner++) {
            const a = corner * Math.PI / 3;
            const x = Math.cos(a) * f.r * 0.28;
            const y = Math.sin(a) * f.r * 0.28;
            if (corner === 0) ctx.moveTo(x, y);
            else ctx.lineTo(x, y);
        }
        ctx.closePath();
        ctx.stroke();
    }
    for (let arm = 0; arm < 6; arm++) {
        ctx.rotate(Math.PI / 3);
        ctx.beginPath();
        ctx.moveTo(0, 0);
        ctx.lineTo(0, -f.r);
        if (f.shape !== 0) {
            const branch = f.r * (f.shape === 1 ? 0.55 : 0.72);
            const twig = f.r * (f.shape === 1 ? 0.27 : 0.22);
            ctx.moveTo(0, -branch);
            ctx.lineTo(-twig, -branch - twig);
            ctx.moveTo(0, -branch);
            ctx.lineTo(twig, -branch - twig);
            if (f.shape === 2) {
                const inner = f.r * 0.43;
                ctx.moveTo(0, -inner);
                ctx.lineTo(-twig * 0.65, -inner - twig * 0.65);
                ctx.moveTo(0, -inner);
                ctx.lineTo(twig * 0.65, -inner - twig * 0.65);
            }
        }
        ctx.stroke();
    }
    ctx.restore();
}

function frame(ts) {
    const dt = lastTs ? Math.min(0.05, (ts - lastTs) / 1000) : 0;
    lastTs = ts;
    ctx.clearRect(0, 0, width, height);
    for (const f of flakes) {
        f.phase += dt * 0.8;
        f.y += f.speed * dt;
        f.x += Math.sin(f.phase) * f.drift * dt;
        f.angle += f.turn * dt;
        if (f.y - f.r > height) Object.assign(f, makeFlake(false));
        if (f.x < -5) f.x = width + 5;
        else if (f.x > width + 5) f.x = -5;
        drawFlake(f);
    }
    raf = requestAnimationFrame(frame);
}

onMounted(() => {
    resize();
    window.addEventListener('resize', resize);
    raf = requestAnimationFrame(frame);
});

onBeforeUnmount(() => {
    cancelAnimationFrame(raf);
    window.removeEventListener('resize', resize);
});
</script>

<template>
    <canvas
        ref="canvasEl"
        class="pointer-events-none fixed inset-x-0 bottom-0 z-20 h-[calc(100dvh-var(--header-h,0px))] w-full"
        :style="{ top: 'var(--header-h, 0px)' }"
        aria-hidden="true"
    ></canvas>
</template>
