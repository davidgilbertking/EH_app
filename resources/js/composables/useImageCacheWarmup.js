const queue = [];
const enqueued = new Set();
let running = false;
let draining = false;

const MAX_CONCURRENT = 6;

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
        img.loading = 'eager';
        img.onload = () => resolve();
        img.onerror = () => resolve();
        img.src = url;
    });
}

function scheduleDrain() {
    if (!isBrowser() || draining) return;
    draining = true;

    const run = () => {
        draining = false;
        drainQueue();
    };

    if (typeof window.requestIdleCallback === 'function') {
        window.requestIdleCallback(run, { timeout: 1000 });
        return;
    }

    window.setTimeout(run, 0);
}

function drainQueue() {
    if (!isBrowser()) return;
    if (running >= MAX_CONCURRENT) return;

    while (running < MAX_CONCURRENT && queue.length > 0) {
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

export function warmImageCache(urls = []) {
    if (!isBrowser() || !Array.isArray(urls) || urls.length === 0) return;

    for (const rawUrl of urls) {
        const url = normalize(rawUrl);
        if (!url || enqueued.has(url)) continue;
        enqueued.add(url);
        queue.push(url);
    }

    if (queue.length > 0) {
        scheduleDrain();
    }
}
