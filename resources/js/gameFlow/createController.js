import { createUuid } from '../utils/uuid.js';
import { isEncounterLightingTarget, resolveLightingTarget } from './lightingTarget.js';

export { createUuid as createSessionId } from '../utils/uuid.js';

const MYTHOS_COLORS = new Set(['green', 'yellow', 'blue']);


function pathname(url) {
    return new URL(url || '/', 'http://eh.local').pathname.replace(/\/$/, '') || '/';
}

// The UI supplies the branch for ambiguous ancient/* music. Legacy blobs can
// safely infer contacts/*, while ancient/* without stored context stays Other.
export function resolveGameContext(folderSlug, explicitContext = null, url = null) {
    if (['action', 'action-muted', 'combat', 'combat-epic', 'mythos'].includes(folderSlug)) return folderSlug;
    // Saved branch context disambiguates ordinary tracks, but cannot turn a
    // different track into Action or Mythos (or override their actual folders).
    if (['encounters', 'other'].includes(explicitContext)) return explicitContext;
    if (url && /^\/encounters(?:\/|$)/.test(pathname(url))) return 'encounters';
    if (folderSlug?.startsWith('contacts/')) return 'encounters';
    return 'other';
}

/** User actions drive lighting; music may wait for the selected transition policy. */
export function createGameFlowController({
    audio,
    lighting,
    canControlLighting = () => false,
    getMusicDelayMs = () => 0,
    getEncounterMusicDelayMs = () => 0,
    schedule = setTimeout,
    unschedule = clearTimeout,
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
        pendingMusicFolder: null,
    });
    let lastCommand = null;
    let lastCommandAt = -Infinity;
    let audioOperation = 0;
    let currentPath = pathname(initialUrl);
    let pendingMusic = null;
    let selectedLightingTarget = null;
    let lightHandoff = null;

    function cancelPendingMusic() {
        const pending = pendingMusic;
        pendingMusic = null;
        state.pendingMusicFolder = null;
        if (pending) unschedule(pending.timer);
        return pending;
    }

    function accept(command) {
        const time = now();
        if (command === lastCommand && time - lastCommandAt < duplicateWindowMs) return false;
        lastCommand = command;
        lastCommandAt = time;
        return true;
    }

    function sendTarget(target) {
        if (!canControlLighting()) return;
        selectedLightingTarget = target;
        // Lighting owns its visible transport errors. A failed lamp never
        // prevents audio or navigation, including synchronous driver failures.
        try { Promise.resolve(lighting.requestTarget(target)).catch(() => {}); } catch (_) { /* isolated */ }
    }

    function runAudio(method, options) {
        const operation = ++audioOperation;
        state.audioError = null;
        try {
            // Ordinary playback stays in the user gesture. A color exit can
            // defer this call while the already-unlocked current audio continues.
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
        state.selectedContext = context;
        clearMythos();
    }

    function retireSettledEncounterHandoff(preserveMusicDeadline = false) {
        if (lightHandoff?.encounter && (!preserveMusicDeadline || lightHandoff.deadline <= now())
            && lighting.isTargetSettled?.(selectedLightingTarget)) {
            lightHandoff = null;
        }
    }

    function musicDelay(target) {
        const immediate = { delay: 0, encounter: false };
        if (!target || !canControlLighting()) return immediate;
        retireSettledEncounterHandoff(true);
        const key = JSON.stringify(target);
        const encounterPending = lightHandoff?.encounter && lighting.isTargetSettled
            && !lighting.isTargetSettled(selectedLightingTarget);
        if (lightHandoff && lightHandoff.deadline <= now() && !encounterPending) {
            lightHandoff = null;
        }
        // Keep the source policy while an encounter exit is still in flight,
        // including after music starts but before the lamp confirms completion.
        const encounter = isEncounterLightingTarget(selectedLightingTarget)
            || isEncounterLightingTarget(target) || lightHandoff?.encounter === true;
        if (!audio.state.playingFolder || audio.state.isPaused) return { delay: 0, encounter };
        // Replacing music for the same light does not restart the lamp fade.
        if (lightHandoff?.key === key) return { delay: Math.max(0, lightHandoff.deadline - now()), encounter };
        if (!lightHandoff && JSON.stringify(selectedLightingTarget) === key) return { delay: 0, encounter };
        const leavesColour = selectedLightingTarget?.kind === 'colour'
            || (selectedLightingTarget?.kind === 'mythos' && selectedLightingTarget.color);
        if (!encounter && !lightHandoff && !leavesColour) return immediate;
        const configured = Number(encounter ? getEncounterMusicDelayMs() : getMusicDelayMs());
        return { delay: Number.isFinite(configured) ? Math.max(0, configured) : 0, encounter };
    }

    function playWithTarget(method, options, target, keepAudio = false) {
        const { delay, encounter } = musicDelay(target);
        cancelPendingMusic();
        // Death changes music only. Keep both the current target and any lamp
        // transition while cancelling music that would otherwise replace it.
        if (!target) {
            runAudio(method, options);
            return;
        }
        if (target.kind === 'white') state.lastWhiteProfile = target.profile;
        if (delay === 0 || keepAudio) {
            lightHandoff = encounter
                ? { key: JSON.stringify(target), deadline: now() + delay, encounter } : null;
            if (!keepAudio) runAudio(method, options);
            sendTarget(target);
            return;
        }

        lightHandoff = { key: JSON.stringify(target), deadline: now() + delay, encounter };
        sendTarget(target);
        const pending = { timer: null };
        pendingMusic = pending;
        state.pendingMusicFolder = options?.folderSlug ?? null;
        pending.timer = schedule(() => {
            if (pendingMusic !== pending) return;
            pendingMusic = null;
            state.pendingMusicFolder = null;
            if (canControlLighting()) runAudio(method, options);
        }, delay);
    }

    function selectOrdinaryMusic(context, method, options) {
        chooseContext(context);
        playWithTarget(method, options, resolveLightingTarget(options.folderSlug));
    }

    function enterMythos(audioOptions = {}) {
        if (!accept('mythos.enter')) return false;
        const cancelledMusic = cancelPendingMusic();
        if (!canControlLighting()) {
            state.selectedContext = 'mythos';
            clearMythos();
            if (audio.state.playingFolder === 'mythos') runAudio('stop');
            else runAudio('play', { folderSlug: 'mythos', label: 'Mythos', crossfade: true, ...audioOptions });
            return true;
        }
        const isNewSession = state.selectedContext !== 'mythos' || !state.mythosSessionId;
        if (isNewSession) {
            state.selectedContext = 'mythos';
            state.mythosSessionId = uuid();
            state.selectedMythosColor = null;
        }
        const options = { folderSlug: 'mythos', label: 'Mythos', crossfade: true, ...audioOptions };
        if (isNewSession) {
            // Returning during a color exit keeps the current Mythos track intact.
            playWithTarget('play', options,
                { kind: 'mythos', mythosSessionId: state.mythosSessionId, color: null },
                Boolean(cancelledMusic && audio.state.playingFolder === 'mythos'));
        } else if (cancelledMusic && audio.state.playingFolder !== 'mythos') {
            playWithTarget('play', options, selectedLightingTarget);
        } else if (audio.state.playingFolder === 'mythos') {
            runAudio('stop');
        } else {
            runAudio('play', options);
        }
        if (currentPath !== '/mythos') navigate('/mythos');
        return true;
    }

    function selectMythosColor(color, sessionId) {
        if (!canControlLighting()) return false;
        if (state.selectedContext !== 'mythos' || !sessionId || sessionId !== state.mythosSessionId) return false;
        if (!MYTHOS_COLORS.has(color)) return false;
        const target = { kind: 'mythos', mythosSessionId: sessionId, color };
        if (color === state.selectedMythosColor && (!lighting.hasTarget || lighting.hasTarget(target))) return false;
        if (!accept(`mythos.color:${sessionId}:${color}`)) return false;
        // Check completion against the previous target before the new colour
        // starts another request and makes its own completion pending again.
        retireSettledEncounterHandoff();
        state.selectedMythosColor = color;
        sendTarget(target);
        return true;
    }

    function selectAction(variant = 'action') {
        if (!['action', 'action-muted'].includes(variant)) return false;
        if (!accept(variant === 'action' ? 'action.tap' : 'action.hold')) return false;
        const playing = audio.state.playingFolder;
        const method = playing === variant || (variant === 'action' && playing === 'action-muted') ? 'stop' : 'play';
        selectOrdinaryMusic(variant, method, { folderSlug: variant, label: variant === 'action' ? 'Action' : 'Muted Action', crossfade: true });
        return true;
    }

    function selectCombat(variant = 'combat') {
        if (!['combat', 'combat-epic'].includes(variant)) return false;
        if (!accept(variant === 'combat' ? 'combat.tap' : 'combat.hold')) return false;
        const playing = audio.state.playingFolder;
        const method = playing === variant || (variant === 'combat' && playing === 'combat-epic') ? 'stop' : 'play';
        selectOrdinaryMusic(variant, method, { folderSlug: variant, label: variant === 'combat' ? 'Combat' : 'Epic Combat', crossfade: true });
        return true;
    }

    function enterEncounters() {
        if (!accept('encounters.enter')) return false;
        navigate('/encounters');
        return true;
    }

    function playUserChoice(options, explicitContext = null) {
        const context = resolveGameContext(options.folderSlug, explicitContext);
        if (context === 'mythos') return enterMythos(options);
        if (!accept(`play:${context}:${options.folderSlug}:${options.mode || ''}`)) return false;
        selectOrdinaryMusic(context, audio.state.playingFolder === options.folderSlug ? 'stop' : 'play', options);
        return true;
    }

    function observeNavigation(url) {
        // Route changes, including leaving Mythos, preserve the active music
        // context and scene. Remember only whether a future Mythos tap must navigate.
        currentPath = pathname(url);
    }

    function stopUserAudio() {
        if (!accept('audio.stop')) return false;
        cancelPendingMusic();
        runAudio('stop');
        return true;
    }

    function togglePause() {
        if (!accept('audio.pause-toggle')) return false;
        cancelPendingMusic();
        runAudio(audio.state.isPaused ? 'resume' : 'pause');
        return true;
    }

    function logout() {
        cancelPendingMusic();
        state.selectedContext = null;
        clearMythos();
        selectedLightingTarget = null;
        lightHandoff = null;
        const fadePromise = Promise.resolve(runAudio('fadeOutCurrent')).catch(() => {});
        let releasePromise;
        try { releasePromise = canControlLighting() ? Promise.resolve(lighting.releaseForLogout()).catch(() => {}) : Promise.resolve(); }
        catch (_) { releasePromise = Promise.resolve(); }
        return { fadePromise, releasePromise };
    }

    return { state, enterMythos, selectMythosColor, selectAction, selectCombat,
        enterEncounters, playUserChoice, observeNavigation,
        stopUserAudio, togglePause, logout, cancelPendingMusic };
}
