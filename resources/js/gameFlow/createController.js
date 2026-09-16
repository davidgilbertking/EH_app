import { createUuid } from '../utils/uuid.js';

export { createUuid as createSessionId } from '../utils/uuid.js';

const MYTHOS_COLORS = new Set(['green', 'yellow', 'blue']);


function pathname(url) {
    return new URL(url || '/', 'http://eh.local').pathname.replace(/\/$/, '') || '/';
}

// The UI supplies the branch for ambiguous ancient/* music. Legacy blobs can
// safely infer contacts/*, while ancient/* without stored context stays Other.
export function resolveGameContext(folderSlug, explicitContext = null, url = null) {
    if (explicitContext) return explicitContext;
    if (['action', 'action-muted', 'combat', 'combat-epic', 'mythos'].includes(folderSlug)) return folderSlug;
    if (url && /^\/encounters(?:\/|$)/.test(pathname(url))) return 'encounters';
    if (folderSlug?.startsWith('contacts/')) return 'encounters';
    return 'other';
}

/** User actions only: no audio watchers, mount effects, or lamp fade timers. */
export function createGameFlowController({
    audio,
    lighting,
    navigate = () => {},
    now = () => globalThis.performance.now(),
    uuid = createUuid,
    reactive = (value) => value,
    duplicateWindowMs = 250,
    initialUrl = '/',
}) {
    const state = reactive({
        selectedContext: null,
        mythosSessionId: null,
        selectedMythosColor: null,
        lastWhiteProfile: 'action',
        audioError: null,
    });
    let lastCommand = null;
    let lastCommandAt = -Infinity;
    let audioOperation = 0;
    let currentPath = pathname(initialUrl);

    function accept(command) {
        const time = now();
        if (command === lastCommand && time - lastCommandAt < duplicateWindowMs) return false;
        lastCommand = command;
        lastCommandAt = time;
        return true;
    }

    function sendTarget(target, acquire = true) {
        // Lighting owns its visible transport errors. A failed lamp never
        // prevents audio or navigation, including synchronous driver failures.
        try { Promise.resolve(lighting.requestTarget(target, { acquire })).catch(() => {}); } catch (_) { /* isolated */ }
    }

    function runAudio(method, options) {
        const operation = ++audioOperation;
        state.audioError = null;
        try {
            // Keep this invocation in the original user-gesture call stack.
            const result = audio[method](options);
            Promise.resolve(result).then(() => {
                if (operation !== audioOperation || method !== 'play') return;
                if (!audio.state.isLoading && audio.state.playingFolder !== options.folderSlug) {
                    state.audioError = 'Unable to start music. Try the music button again.';
                }
            }).catch(() => {
                if (operation === audioOperation) state.audioError = 'Unable to start music. Try again.';
            });
            return result;
        } catch (_) {
            state.audioError = 'Unable to start music. Try again.';
            return undefined;
        }
    }

    function clearMythos() {
        state.mythosSessionId = null;
        state.selectedMythosColor = null;
    }

    function chooseContext(context) {
        const wasMythos = state.selectedContext === 'mythos';
        state.selectedContext = context;
        clearMythos();
        if (context === 'action' || context === 'action-muted') {
            state.lastWhiteProfile = 'action';
            return { kind: 'white', profile: 'action' };
        }
        if (context === 'encounters') {
            state.lastWhiteProfile = 'encounters';
            return { kind: 'white', profile: 'encounters' };
        }
        return wasMythos ? { kind: 'white', profile: state.lastWhiteProfile } : null;
    }

    function enterMythos(audioOptions = {}) {
        if (!accept('mythos.enter')) return false;
        const isNewSession = state.selectedContext !== 'mythos';
        if (isNewSession) {
            state.selectedContext = 'mythos';
            state.mythosSessionId = uuid();
            state.selectedMythosColor = null;
        }
        if (!isNewSession && audio.state.playingFolder === 'mythos') runAudio('stop');
        else runAudio('play', { folderSlug: 'mythos', label: 'Mythos', crossfade: true, ...audioOptions });
        if (isNewSession) sendTarget({ kind: 'mythos', mythosSessionId: state.mythosSessionId, color: null });
        if (currentPath !== '/mythos') navigate('/mythos');
        return true;
    }

    function selectMythosColor(color, sessionId) {
        if (state.selectedContext !== 'mythos' || !sessionId || sessionId !== state.mythosSessionId) return false;
        if (!MYTHOS_COLORS.has(color)) return false;
        const target = { kind: 'mythos', mythosSessionId: sessionId, color };
        if (color === state.selectedMythosColor && (!lighting.hasTarget || lighting.hasTarget(target))) return false;
        if (!accept(`mythos.color:${sessionId}:${color}`)) return false;
        state.selectedMythosColor = color;
        sendTarget(target);
        return true;
    }

    function selectAction(variant = 'action') {
        if (!['action', 'action-muted'].includes(variant)) return false;
        if (!accept(variant === 'action' ? 'action.tap' : 'action.hold')) return false;
        const target = chooseContext(variant);
        const playing = audio.state.playingFolder;
        if (playing === variant || (variant === 'action' && playing === 'action-muted')) runAudio('stop');
        else runAudio('play', { folderSlug: variant, label: variant === 'action' ? 'Action' : 'Muted Action', crossfade: true });
        sendTarget(target);
        return true;
    }

    function selectCombat(variant = 'combat') {
        if (!['combat', 'combat-epic'].includes(variant)) return false;
        if (!accept(variant === 'combat' ? 'combat.tap' : 'combat.hold')) return false;
        const target = chooseContext(variant);
        const playing = audio.state.playingFolder;
        if (playing === variant || (variant === 'combat' && playing === 'combat-epic')) runAudio('stop');
        else runAudio('play', { folderSlug: variant, label: variant === 'combat' ? 'Combat' : 'Epic Combat', crossfade: true });
        if (target) sendTarget(target);
        return true;
    }

    function enterEncounters() {
        if (!accept('encounters.enter')) return false;
        sendTarget(chooseContext('encounters'));
        navigate('/encounters');
        return true;
    }

    function playUserChoice(options, explicitContext = null) {
        const context = resolveGameContext(options.folderSlug, explicitContext);
        if (context === 'mythos') return enterMythos(options);
        if (!accept(`play:${context}:${options.folderSlug}:${options.mode || ''}`)) return false;
        const target = chooseContext(context);
        if (audio.state.playingFolder === options.folderSlug) runAudio('stop');
        else runAudio('play', options);
        if (target) sendTarget(target);
        return true;
    }

    function leaveMythos(nextContext = 'other', acquire = true) {
        if (state.selectedContext !== 'mythos') return false;
        sendTarget(chooseContext(nextContext), acquire);
        return true;
    }

    function observeNavigation(url) {
        const nextPath = pathname(url);
        // Also cover a new route winning before the /mythos visit completes.
        // Same-path props/history refreshes never terminate the selected phase.
        const leftMythosPage = currentPath !== nextPath && nextPath !== '/mythos';
        currentPath = nextPath;
        if (leftMythosPage) leaveMythos('other', false);
    }

    function restoreNormalLight() {
        if (!accept('light.restore')) return false;
        state.lastWhiteProfile = 'action';
        state.selectedContext = 'action';
        clearMythos();
        sendTarget({ kind: 'white', profile: state.lastWhiteProfile });
        return true;
    }

    function stopUserAudio() {
        if (!accept('audio.stop')) return false;
        runAudio('stop');
        return true;
    }

    function togglePause() {
        if (!accept('audio.pause-toggle')) return false;
        runAudio(audio.state.isPaused ? 'resume' : 'pause');
        return true;
    }

    function logout() {
        state.selectedContext = null;
        clearMythos();
        const fadePromise = Promise.resolve(runAudio('fadeOutCurrent')).catch(() => {});
        let releasePromise;
        try { releasePromise = Promise.resolve(lighting.releaseForLogout()).catch(() => {}); }
        catch (_) { releasePromise = Promise.resolve(); }
        return { fadePromise, releasePromise };
    }

    return { state, enterMythos, selectMythosColor, selectAction, selectCombat,
        enterEncounters, playUserChoice, leaveMythos, observeNavigation,
        restoreNormalLight, stopUserAudio, togglePause, logout };
}
