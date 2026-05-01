export function makeBlobId(prefix = 'blob-') {
    if (globalThis.crypto?.randomUUID) {
        return prefix + globalThis.crypto.randomUUID();
    }

    if (globalThis.crypto?.getRandomValues) {
        const bytes = new Uint8Array(16);
        globalThis.crypto.getRandomValues(bytes);
        const hex = Array.from(bytes, (b) => b.toString(16).padStart(2, '0')).join('');
        return prefix + hex;
    }

    return `${prefix}${Date.now().toString(36)}-${Math.random().toString(36).slice(2, 10)}`;
}
