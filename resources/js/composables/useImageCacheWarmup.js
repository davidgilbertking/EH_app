const queue = [];
const enqueued = new Set();
let running = false;
let draining = false;
let warmupStartTimer = null;

const DEFAULT_MAX_CONCURRENT = 3;
const SLOW_MAX_CONCURRENT = 1;
const WARMUP_START_DELAY_MS = 2500;
const IDLE_TIMEOUT_MS = 4000;

function isBrowser() {
    return typeof window !== 'undefined' && typeof Image !== 'undefined';
}

function normalize(url) {
    if (!url || typeof url !== 'string') return null;
    const trimmed = url.trim();
    return trimmed.length ? trimmed : null;
}

function loadImage(url) {
    return new Promise((resolve) => {
        const img = new Image();
        img.decoding = 'async';
        img.loading = 'lazy';
        if ('fetchPriority' in img) {
            img.fetchPriority = 'low';
        }
        img.onload = () => resolve();
        img.onerror = () => resolve();
        img.src = url;
    });
}

function maxConcurrentLoads() {
    if (!isBrowser()) return DEFAULT_MAX_CONCURRENT;

    const connection = navigator.connection
        || navigator.mozConnection
        || navigator.webkitConnection
        || null;

    if (!connection) return DEFAULT_MAX_CONCURRENT;
    if (connection.saveData) return SLOW_MAX_CONCURRENT;

    const effectiveType = typeof connection.effectiveType === 'string'
        ? connection.effectiveType
        : '';
    if (effectiveType.includes('2g') || effectiveType === '3g') {
        return SLOW_MAX_CONCURRENT;
    }

    const downlink = typeof connection.downlink === 'number'
        ? connection.downlink
        : null;
    if (downlink !== null && downlink > 0 && downlink < 5) {
        return 2;
    }

    return DEFAULT_MAX_CONCURRENT;
}

function scheduleDrain() {
    if (!isBrowser() || draining) return;
    draining = true;

    const run = () => {
        draining = false;
        drainQueue();
    };

    if (typeof window.requestIdleCallback === 'function') {
        window.requestIdleCallback(run, { timeout: IDLE_TIMEOUT_MS });
        return;
    }

    window.setTimeout(run, 0);
}

function drainQueue() {
    if (!isBrowser()) return;
    const maxConcurrent = maxConcurrentLoads();
    if (running >= maxConcurrent) return;

    while (running < maxConcurrent && queue.length > 0) {
        const url = queue.shift();
        if (!url) continue;

        running += 1;
        loadImage(url).finally(() => {
            running -= 1;
            if (queue.length > 0) {
                scheduleDrain();
            }
        });
    }
}

function scheduleWarmupStart() {
    if (!isBrowser()) return;
    if (warmupStartTimer !== null) return;

    warmupStartTimer = window.setTimeout(() => {
        warmupStartTimer = null;
        scheduleDrain();
    }, WARMUP_START_DELAY_MS);
}

export function warmImageCache(urls = []) {
    if (!isBrowser() || !Array.isArray(urls) || urls.length === 0) return;

    for (const rawUrl of urls) {
        const url = normalize(rawUrl);
        if (!url || enqueued.has(url)) continue;
        enqueued.add(url);
        queue.push(url);
    }

    if (queue.length > 0) {
        scheduleWarmupStart();
    }
}
