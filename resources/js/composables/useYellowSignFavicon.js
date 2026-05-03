import { makeYellowSignDataUrl } from '@/utils/yellowSign';
import { onMounted, unref, watch } from 'vue';

function upsertFavicon(seed) {
    if (typeof document === 'undefined') return;
    const dataUrl = makeYellowSignDataUrl(seed, {
        size: 64,
        color: '#f2c94c',
        strokeWidth: 4,
    });

    let link = document.querySelector('link[data-yellow-sign-favicon="1"]');
    if (!link) {
        link = document.createElement('link');
        link.setAttribute('data-yellow-sign-favicon', '1');
        link.rel = 'icon';
        link.type = 'image/svg+xml';
        link.sizes = 'any';
        document.head.appendChild(link);
    }

    link.href = dataUrl;
}

export function useYellowSignFavicon(seedRef) {
    const apply = () => {
        const seed = unref(seedRef);
        if (!seed) return;
        upsertFavicon(seed);
    };

    onMounted(apply);
    watch(() => unref(seedRef), apply);
}
