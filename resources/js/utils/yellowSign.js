function hashSeed(seed) {
    const input = String(seed ?? 'the-yellow-sign');
    let h = 2166136261;
    for (let i = 0; i < input.length; i += 1) {
        h ^= input.charCodeAt(i);
        h = Math.imul(h, 16777619);
    }
    return h >>> 0;
}

function mulberry32(a) {
    return () => {
        let t = (a += 0x6d2b79f5);
        t = Math.imul(t ^ (t >>> 15), t | 1);
        t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
        return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
    };
}

function f(value) {
    return Number(value).toFixed(1);
}

export function buildYellowSignPaths(seed) {
    const rnd = mulberry32(hashSeed(seed) || 1);

    const cx = 32 + (rnd() - 0.5) * 4;
    const topY = 6 + rnd() * 6;
    const splitY = 24 + rnd() * 8;
    const waistY = 35 + rnd() * 7;
    const bottomY = 56 - rnd() * 4;

    const leftX = 10 + rnd() * 9;
    const rightX = 46 + rnd() * 9;
    const armY = 17 + rnd() * 8;
    const hookY = 45 + rnd() * 8;

    const paths = [
        `M ${f(cx)} ${f(topY)} C ${f(cx - 8 - rnd() * 4)} ${f(topY + 8 + rnd() * 5)}, ${f(cx + 8 + rnd() * 4)} ${f(splitY - rnd() * 5)}, ${f(cx)} ${f(bottomY)}`,
        `M ${f(leftX)} ${f(armY)} C ${f(cx - 10 - rnd() * 3)} ${f(armY - 5 - rnd() * 4)}, ${f(cx - 3)} ${f(waistY - 2)}, ${f(cx)} ${f(waistY)}`,
        `M ${f(rightX)} ${f(armY + (rnd() - 0.5) * 2)} C ${f(cx + 10 + rnd() * 3)} ${f(armY - 4 - rnd() * 4)}, ${f(cx + 3)} ${f(waistY + 2)}, ${f(cx)} ${f(waistY)}`,
        `M ${f(cx - 9 - rnd() * 4)} ${f(hookY)} C ${f(cx - 2)} ${f(hookY + 9 + rnd() * 3)}, ${f(cx + 8 + rnd() * 4)} ${f(hookY + 7 + rnd() * 3)}, ${f(cx + 13 + rnd() * 4)} ${f(hookY - 2 - rnd() * 4)}`,
    ];

    if (rnd() > 0.45) {
        paths.push(
            `M ${f(cx - 3 - rnd() * 4)} ${f(topY + 4 + rnd() * 5)} L ${f(cx + 10 + rnd() * 6)} ${f(topY + rnd() * 5)}`
        );
    }

    if (rnd() > 0.6) {
        paths.push(
            `M ${f(cx - 2 - rnd() * 3)} ${f(waistY - 11 - rnd() * 3)} C ${f(cx + 1 + rnd() * 4)} ${f(waistY - 16 - rnd() * 4)}, ${f(cx + 9 + rnd() * 4)} ${f(waistY - 12 - rnd() * 3)}, ${f(cx + 7 + rnd() * 3)} ${f(waistY - 6 - rnd() * 2)}`
        );
    }

    return paths;
}

export function makeYellowSignSvg(seed, opts = {}) {
    const size = Number(opts.size ?? 64);
    const color = String(opts.color ?? '#f2c94c');
    const strokeWidth = Number(opts.strokeWidth ?? 4);
    const paths = buildYellowSignPaths(seed)
        .map((d) => `<path d="${d}" />`)
        .join('');

    return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" width="${size}" height="${size}" fill="none" stroke="${color}" stroke-width="${strokeWidth}" stroke-linecap="round" stroke-linejoin="round">${paths}</svg>`;
}

export function makeYellowSignDataUrl(seed, opts = {}) {
    const svg = makeYellowSignSvg(seed, opts);
    return `data:image/svg+xml;utf8,${encodeURIComponent(svg)}`;
}
