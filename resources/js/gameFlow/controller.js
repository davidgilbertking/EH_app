import { reactive } from 'vue';
import { router } from '@inertiajs/vue3';
import { engine } from '@/audio/engine';
import { lighting } from '@/lighting/state';
import { createGameFlowController } from './createController.js';

export { resolveGameContext, createGameFlowController } from './createController.js';

export const gameFlow = createGameFlowController({
    audio: engine,
    lighting,
    canControlLighting: () => lighting.state.canControl,
    reactive,
    navigate: (url) => router.get(url),
    initialUrl: typeof window === 'undefined' ? '/' : window.location.pathname,
});
