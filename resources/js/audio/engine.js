import { Howl, Howler } from 'howler';
import { reactive } from 'vue';

/**
 * Audio engine for Eldritch Horror music switcher.
 *
 * Behaviour (per project spec):
 *  - Each "play(folderSlug)" call asks the server for a random track in that folder
 *    and starts playing it.
 *  - mode = 'random_pos_fade': seek to a random position in the first ~60% of the
 *    track; fade in over 3s.
 *  - mode = 'from_start_no_fade': start at 0; full volume immediately.
 *  - When a new track starts and another track is already playing, the current track
 *    fades out over 2s while the new one starts (cross-fade).
 *  - stop() fades out the currently-playing track over 2s.
 *
 * Safari/iOS: Howler with html5=true uses HTMLAudioElement so streaming large MP3s
 * over HTTP Range works without preloading the full file.
 *
 * Module-level singleton: survives Inertia page navigations because Inertia only
 * remounts Page components, not the JS module graph.
 */

// Old track fade-out duration. Long enough to sound like a proper musical
// taper (not an abrupt cut), but short enough that the overlap between the
// tail of the old track and the start of the new one stays minimal — the
// new track still needs ~500-1500ms to fetch metadata + buffer over HTTP,
// so by the time it actually starts, the old one is already mostly gone.
const FADE_OUT_MS = 1800;
const FADE_IN_MS = 1800;
const HARD_SWITCH_FADE_OUT_MS = 1800;
const HARD_SWITCH_FADE_IN_MS = 1800;
const PAUSE_TOGGLE_FADE_MS = 1250;
const RANDOM_POS_MAX_FRACTION = 0.6;
const MOBILE_FADE_START_FALLBACK_MS = 1500;
const MOBILE_FADE_INTERVAL_MS = 50;
const RANDOM_FRAGMENT_SETTLE_CHECK_MS = 120;
const RANDOM_FRAGMENT_SEEK_TOLERANCE_SEC = 2.5;
const APPLE_MOBILE_FADE_SETTLE_MS = 80;
const DEFAULT_TRACK_NORMALIZATION_GAIN = 1;
const MIN_TRACK_NORMALIZATION_GAIN = 0.05;
const MAX_TRACK_NORMALIZATION_GAIN = 4;
const DEFAULT_MASTER_VOLUME = 1;
const MASTER_VOLUME_STORAGE_KEY = 'eh:master-volume:v1';
const MASTER_VOLUME_SMOOTHING_FACTOR = 0.16;
const MASTER_VOLUME_EPSILON = 0.002;
// Never start a random-pos track inside the final TAIL_PROTECTION_SEC seconds.
// Assumption: no tracks are shorter than this value.
const TAIL_PROTECTION_SEC = 60;

export const MODE_RANDOM_POS_FADE = 'random_pos_fade';
export const MODE_FROM_START_NO_FADE = 'from_start_no_fade';

class AudioEngine {
    constructor() {
        this.current = null; // { howl, soundId, folderSlug, label, mode }
        this._pauseFadeTimer = null;
        this._pauseOpToken = 0;
        this._playRequestToken = 0;
        this._masterVolumeTarget = DEFAULT_MASTER_VOLUME;
        this._masterVolumeApplied = DEFAULT_MASTER_VOLUME;
        this._masterVolumeRaf = null;
        this._pendingMobileFadeCleanup = new WeakMap();
        this._pendingFadeOutCleanup = new WeakMap();
        this._appleMobileContext = null;
        this._appleMobileMasterGain = null;
        this._appleMobileGraphByNode = new WeakMap();
        this._appleMobileGraphByHowl = new WeakMap();
        this._trackNormalizationGainByHowl = new WeakMap();
        // Reactive surface for Vue components to bind to.
        this.state = reactive({
            playingFolder: null, // folder slug currently active (or null)
            playingLabel: null,
            isLoading: false,
            isPaused: false,
            canResume: false,
            pausedFolder: null, // folder slug paused via corner pause button
            pausedLabel: null,
            masterVolume: DEFAULT_MASTER_VOLUME,
        });

        const initialMasterVolume = this._readStoredMasterVolume();
        this._masterVolumeTarget = initialMasterVolume;
        this._masterVolumeApplied = initialMasterVolume;
        this.state.masterVolume = initialMasterVolume;
        this._applyMasterVolume(initialMasterVolume);
    }

    setMasterVolume(volume, { smooth = true } = {}) {
        const next = this._clampVolume(volume);
        this._masterVolumeTarget = next;
        this.state.masterVolume = next;
        this._persistMasterVolume(next);

        if (!smooth) {
            this._applyMasterVolume(next);
            return next;
        }

        this._startMasterVolumeSmoothing();
        return next;
    }

    /**
     * Play a random track from the given folder.
     * @param {Object} opts
     * @param {string}  opts.folderSlug
     * @param {string}  [opts.mode]        Optional override of folder's default mode.
     * @param {string}  [opts.label]       Optional UI label shown in state.
     * @param {boolean} [opts.hardSwitch]  If true, wait for the previous track
     *                                     to fully fade out before starting the
     *                                     new one. Used for "phase change"
     *                                     buttons (Action/Combat/Mythos/etc.)
     *                                     where overlap between phases is
     *                                     undesirable.
     */
    async play({ folderSlug, mode, label, hardSwitch = false, crossfade = true }) {
        if (!folderSlug) return;
        const requestToken = ++this._playRequestToken;
        this._cancelPauseTransition();
        // Ensure custom media-element graph context is resumed inside the
        // user gesture call stack (important for autoplay policy compliance).
        this._resumeAppleMobileAudioContext();
        let hardSwitchUntilTs = null;
        let startupFadeInMs = FADE_IN_MS;

        // If there is a paused track and user explicitly starts another folder,
        // discard paused buffer and treat this as a brand new playback choice.
        if (this.current && this.state.isPaused) {
            try {
                if (this.current.soundId != null) {
                    this.current.howl.stop(this.current.soundId);
                } else {
                    this.current.howl.stop();
                }
                this.current.howl.unload();
            } catch (_) { /* ignore */ }
            this.current = null;
            this._clearActiveState();
        }

        // Three modes for handling the previous track:
        //   - hardSwitch: fade out, await full silence, then load new (phase
        //     buttons in the header — no overlap allowed).
        //   - crossfade:  keep old playing at full volume during the network
        //     fetch + load of the new one; old fades out the moment the new
        //     one actually starts (blob re-taps — no silent gap).
        //   - default:    fade out old IMMEDIATELY, in parallel with the
        //     fetch. UI feels responsive even if the new track takes a second
        //     or two to load.
        let prevHowlForCrossfade = null;
        if (this.current) {
            if (crossfade) {
                prevHowlForCrossfade = {
                    howl: this.current.howl,
                    soundId: this.current.soundId ?? null,
                };
            } else {
                const prev = this.current;
                this.current = null;
                this._clearActiveState();
                const fadeOutMs = hardSwitch ? HARD_SWITCH_FADE_OUT_MS : FADE_OUT_MS;
                this._fadeOutAndUnload(prev.howl, prev.soundId ?? null, fadeOutMs);
                if (hardSwitch) {
                    hardSwitchUntilTs = Date.now() + fadeOutMs + 80;
                    startupFadeInMs = HARD_SWITCH_FADE_IN_MS;
                }
            }
        }

        this.state.isLoading = true;
        try {
            const res = await fetch(
                `/audio/folder/${encodeURIComponent(folderSlug)}/random`,
                { headers: { Accept: 'application/json' } }
            );
            if (requestToken !== this._playRequestToken) return;

            if (!res.ok) {
                console.warn('[engine] pick failed', folderSlug, res.status);
                this.state.isLoading = false;
                this._clearActiveState();
                // If we promised a crossfade and never started a new track,
                // still kill the old one so we don't leak a Howl.
                if (prevHowlForCrossfade) {
                    this._fadeOutAndUnload(
                        prevHowlForCrossfade.howl,
                        prevHowlForCrossfade.soundId ?? null,
                    );
                }
                return;
            }
            const pick = await res.json();
            if (requestToken !== this._playRequestToken) return;
            const effectiveMode = mode || pick.mode || MODE_RANDOM_POS_FADE;

            this._startTrack({
                streamUrl: pick.streamUrl,
                durationSec: pick.durationSec,
                mode: effectiveMode,
                folderSlug,
                label: label || pick.folderName || folderSlug,
                format: pick.format || null,
                normalizationGain: pick.normalizationGain ?? DEFAULT_TRACK_NORMALIZATION_GAIN,
                prevHowlForCrossfade,
                requestToken,
                hardSwitchUntilTs,
                fadeInMs: startupFadeInMs,
            });
        } catch (e) {
            if (requestToken !== this._playRequestToken) return;
            console.error('[engine] play error', e);
            this._clearActiveState();
            if (prevHowlForCrossfade) {
                this._fadeOutAndUnload(
                    prevHowlForCrossfade.howl,
                    prevHowlForCrossfade.soundId ?? null,
                );
            }
        } finally {
            if (requestToken === this._playRequestToken) {
                this.state.isLoading = false;
            }
        }
    }

    /** Stop the currently-playing track with fade-out. */
    stop() {
        this._cancelPauseTransition();
        if (!this.current) return;
        if (this.state.isPaused) {
            try {
                if (this.current.soundId != null) {
                    this.current.howl.stop(this.current.soundId);
                } else {
                    this.current.howl.stop();
                }
                this.current.howl.unload();
            } catch (_) { /* ignore */ }
        } else {
            this._fadeOutAndUnload(this.current.howl, this.current.soundId ?? null);
        }
        this.current = null;
        this._clearActiveState();
    }

    pause() {
        if (!this.current || this.state.isPaused) return false;

        this._cancelPauseTransition();
        const opToken = ++this._pauseOpToken;
        const howl = this.current.howl;
        const soundId = this.current.soundId ?? null;

        // Reflect paused state in UI immediately while audio fades out.
        this.state.isPaused = true;
        this.state.playingFolder = null;
        this.state.playingLabel = null;
        this.state.canResume = true;
        this.state.pausedFolder = this.current.folderSlug;
        this.state.pausedLabel = this.current.label;

        try {
            const startVol = this._getTrackVolume(howl, soundId);
            const finalizePause = () => {
                if (opToken !== this._pauseOpToken) return;
                try {
                    if (soundId != null) {
                        howl.pause(soundId);
                    } else {
                        howl.pause();
                    }
                    // Keep at 0 so resume can fade in from silence.
                    this._setTrackVolumeImmediate(howl, soundId, 0);
                } catch (_) { /* ignore */ }
                this._pauseFadeTimer = null;
            };
            const fadeStarted = this._fadeTrackVolume(
                howl,
                soundId,
                startVol,
                0,
                PAUSE_TOGGLE_FADE_MS,
                finalizePause,
            );
            if (!fadeStarted) {
                finalizePause();
            }
            return true;
        } catch (_) {
            try {
                if (soundId != null) {
                    howl.pause(soundId);
                } else {
                    howl.pause();
                }
                this._setTrackVolumeImmediate(howl, soundId, 0);
                return true;
            } catch (__) {
                // Revert UI markers if pause could not be applied.
                this.state.isPaused = false;
                this.state.playingFolder = this.current?.folderSlug || null;
                this.state.playingLabel = this.current?.label || null;
                this.state.canResume = Boolean(this.current);
                this.state.pausedFolder = null;
                this.state.pausedLabel = null;
                return false;
            }
        }
    }

    resume() {
        if (!this.current || !this.state.isPaused) return false;

        this._cancelPauseTransition();
        const howl = this.current.howl;
        let soundId = this.current.soundId ?? null;

        try {
            // Pause uses fade-out. If user resumes quickly, kill unfinished fade
            // first, otherwise stale interval can fight with resumed volume.
            this._stopTrackFade(howl, soundId);

            const fadeIn = (idHint = null) => {
                try {
                    if (!this.current || this.current.howl !== howl) return;
                    const id = this.current.soundId ?? idHint ?? null;
                    const startVol = this._getTrackVolume(howl, id);
                    if (startVol >= 1) return;
                    this._fadeTrackVolume(howl, id, startVol, 1, PAUSE_TOGGLE_FADE_MS);
                } catch (_) { /* ignore */ }
            };

            // Run fade-in when resume actually enters playing state.
            if (soundId != null) {
                howl.once('play', (playedId) => fadeIn(playedId), soundId);
            } else {
                howl.once('play', (playedId) => fadeIn(playedId));
            }

            if (soundId != null) {
                const resumedId = howl.play(soundId);
                if (typeof resumedId === 'number') {
                    soundId = resumedId;
                    this.current.soundId = resumedId;
                }
            } else {
                const resumedId = howl.play();
                if (typeof resumedId === 'number') {
                    soundId = resumedId;
                    this.current.soundId = resumedId;
                }
            }

            // Fallback in case current browser path starts instantly and "play"
            // callback timing is odd.
            fadeIn(soundId);

            this.state.isPaused = false;
            this.state.playingFolder = this.current.folderSlug;
            this.state.playingLabel = this.current.label;
            this.state.canResume = true;
            this.state.pausedFolder = null;
            this.state.pausedLabel = null;
            return true;
        } catch (_) {
            // Keep paused markers if resume failed.
            this.state.isPaused = true;
            this.state.playingFolder = null;
            this.state.playingLabel = null;
            this.state.canResume = true;
            this.state.pausedFolder = this.current.folderSlug;
            this.state.pausedLabel = this.current.label;
            return false;
        }
    }

    /** Internal: kick off a new Howl instance and crossfade with current if any. */
    _startTrack({
        streamUrl,
        durationSec,
        mode,
        folderSlug,
        label,
        format,
        normalizationGain = DEFAULT_TRACK_NORMALIZATION_GAIN,
        prevHowlForCrossfade = null,
        requestToken,
        hardSwitchUntilTs = null,
        fadeInMs = FADE_IN_MS,
    }) {
        const useFadeIn = mode !== MODE_FROM_START_NO_FADE;
        const useRandomPos = mode !== MODE_FROM_START_NO_FADE;
        const useHtml5Streaming = true;
        const trackNormalizationGain = this._clampTrackNormalizationGain(normalizationGain);
        const isIpadDevice = this._isIpadDevice();
        const randomStartSec = useRandomPos
            ? this._pickRandomStartSec(durationSec)
            : null;
        const useRandomStartFragment = Boolean(
            useHtml5Streaming
            && useRandomPos
            && Number.isFinite(randomStartSec)
            && randomStartSec > 0
            // iPad Safari can terminate long playback shortly after start when
            // media fragment offsets are used on streamed tracks.
            && !isIpadDevice
        );
        const streamSrc = useRandomStartFragment
            ? `${streamUrl}#t=${randomStartSec.toFixed(3)}`
            : streamUrl;
        const hardSwitchGateMs = Math.max(0, Number(hardSwitchUntilTs || 0) - Date.now());
        const initialVolume = (hardSwitchGateMs > 0 || useFadeIn) ? 0 : 1;

        // seekApplied guards against the onplay handler firing more than once
        // per Howl (e.g. on loop or re-seek) and re-randomising the position.
        let seekApplied = false;

        const howl = new Howl({
            src: [streamSrc],
            // Stream URL has no file extension; Howler/browsers can't sniff codec
            // from URL alone. Pass the format hint from the server.
            format: format ? [format] : undefined,
            html5: useHtml5Streaming,
            preload: useHtml5Streaming ? 'metadata' : true,
            volume: initialVolume,
            onload: () => {
                if (
                    requestToken !== this._playRequestToken
                    || this.current?.howl !== howl
                ) {
                    this._safeUnload(howl);
                    return;
                }
                // HTML5 path queues playback right after Howl creation to shave
                // startup latency. Avoid a second play() here.
                if (useHtml5Streaming) {
                    return;
                }
                // Kick off playback as soon as metadata loads. Seeking and fading
                // are deferred to `onplay` because, with html5:true, calling
                // seek() before the audio element has actually started playing
                // silently fails in some browsers (Chrome especially).
                const id = howl.play();
                if (this.current?.howl === howl && typeof id === 'number') {
                    this.current.soundId = id;
                }
            },
            onplay: (id) => {
                if (
                    requestToken !== this._playRequestToken
                    || this.current?.howl !== howl
                ) {
                    // Request was superseded by a newer play(). If this track was
                    // supposed to crossfade an older one, still retire that older
                    // howl so we don't end up with layered leftovers.
                    if (prevHowlForCrossfade) {
                        this._fadeOutAndUnload(
                            prevHowlForCrossfade.howl,
                            prevHowlForCrossfade.soundId ?? null,
                        );
                        prevHowlForCrossfade = null;
                    }
                    try { howl.stop(id); } catch (_) { /* ignore */ }
                    this._safeUnload(howl);
                    return;
                }
                if (this.current?.howl === howl && typeof id === 'number') {
                    this.current.soundId = id;
                }
                let plannedPos = null;
                let waitForFragmentSettle = false;
                if (!seekApplied && useRandomPos) {
                    seekApplied = true;
                    const pos = Number.isFinite(randomStartSec)
                        ? randomStartSec
                        : this._pickRandomStartSec(howl.duration());
                    if (Number.isFinite(pos) && pos > 0) {
                        plannedPos = pos;
                        if (useRandomStartFragment) {
                            // Delay audible fade very briefly so we can verify
                            // whether #t= was honored. If not, seek while volume
                            // is still 0 to avoid an audible "jump".
                            waitForFragmentSettle = true;
                        } else {
                            try {
                                if (typeof id === 'number') {
                                    howl.seek(pos, id);
                                } else {
                                    howl.seek(pos);
                                }
                            } catch (_) { /* ignore */ }
                        }
                    }
                }
                const activeId = this.current?.howl === howl
                    ? (this.current.soundId ?? null)
                    : null;
                const currentVol = this._getTrackVolume(howl, activeId);
                const fadeId = activeId ?? (typeof id === 'number' ? id : null);
                const makeAudibleNow = () => {
                    if (
                        requestToken !== this._playRequestToken
                        || this.current?.howl !== howl
                    ) {
                        return;
                    }
                    const shouldFadeIn = useFadeIn || Boolean(prevHowlForCrossfade);
                    if (shouldFadeIn) {
                        this._setTrackVolumeImmediate(howl, fadeId, 0);
                        this._fadeInWhenPlaybackStabilizes(howl, fadeId, fadeInMs);
                    } else if (hardSwitchGateMs > 0 && currentVol < 1) {
                        this._setTrackVolumeImmediate(howl, fadeId, 1);
                    } else {
                        // Still force-apply volume once so per-track normalization
                        // is active even on immediate starts with no fade-in.
                        this._setTrackVolumeImmediate(howl, fadeId, 1);
                    }
                    // Crossfade: only NOW (when the new track is actually audible)
                    // do we start fading the previous one. Guarantees no silent
                    // gap when the user re-taps a blob or switches between blobs.
                    if (prevHowlForCrossfade) {
                        this._fadeOutAndUnload(
                            prevHowlForCrossfade.howl,
                            prevHowlForCrossfade.soundId ?? null,
                        );
                        prevHowlForCrossfade = null;
                    }
                };
                const makeAudible = () => {
                    if (hardSwitchGateMs > 0) {
                        setTimeout(makeAudibleNow, hardSwitchGateMs);
                        return;
                    }
                    makeAudibleNow();
                };

                if (waitForFragmentSettle && Number.isFinite(plannedPos) && plannedPos > 0) {
                    setTimeout(() => {
                        if (
                            requestToken !== this._playRequestToken
                            || this.current?.howl !== howl
                        ) {
                            return;
                        }
                        const seekId = this.current.soundId ?? (typeof id === 'number' ? id : null);
                        const currentPos = Number(
                            seekId != null
                                ? howl.seek(seekId)
                                : howl.seek()
                        );
                        if (
                            !Number.isFinite(currentPos)
                            || currentPos < Math.max(1, plannedPos - RANDOM_FRAGMENT_SEEK_TOLERANCE_SEC)
                        ) {
                            try {
                                if (seekId != null) {
                                    howl.seek(plannedPos, seekId);
                                } else {
                                    howl.seek(plannedPos);
                                }
                            } catch (_) { /* ignore */ }
                        }
                        makeAudible();
                    }, RANDOM_FRAGMENT_SETTLE_CHECK_MS);
                } else {
                    makeAudible();
                }
            },
            // Auto-continue: when a track finishes naturally, pick another random
            // one from the same folder and fade it in. Only triggers if this howl
            // is still the active track (user hasn't switched to a different folder
            // or pressed stop).
            onend: () => {
                if (this.current?.howl !== howl) return;
                // Auto-continue: pick another random track from the same folder.
                // Do NOT hardcode a mode here — let the backend / folder decide.
                // Example: 'action' folder = from_start_no_fade so the next
                // track plays from 00:00; random_pos_fade folders stay random.
                this.play({ folderSlug, label });
            },
            onplayerror: (id, err) => {
                console.warn('[engine] play error', err);
                if (
                    requestToken !== this._playRequestToken
                    || this.current?.howl !== howl
                ) {
                    return;
                }
                // Happens when the browser blocks autoplay. Retry once the user
                // interacts again (Howler's built-in 'unlock' event).
                howl.once('unlock', () => {
                    if (
                        requestToken !== this._playRequestToken
                        || this.current?.howl !== howl
                    ) {
                        return;
                    }
                    const resumedId = howl.play(id);
                    if (this.current?.howl === howl && typeof resumedId === 'number') {
                        this.current.soundId = resumedId;
                    }
                });
            },
            onloaderror: (id, err) => {
                console.warn('[engine] load error', err);
                if (this.current?.howl === howl) {
                    this.current = null;
                    this._clearActiveState();
                }
                this._safeUnload(howl);
            },
        });

        this._trackNormalizationGainByHowl.set(howl, trackNormalizationGain);

        // Any previous track was already faded out in play() before the fetch,
        // so we don't need to crossfade here.
        this.current = {
            howl,
            soundId: null,
            folderSlug,
            label,
            mode,
            normalizationGain: trackNormalizationGain,
        };
        this.state.playingFolder = folderSlug;
        this.state.playingLabel = label;
        this.state.isPaused = false;
        this.state.canResume = true;
        this.state.pausedFolder = null;
        this.state.pausedLabel = null;

        // For HTML5 streaming, queue play immediately instead of waiting for
        // the onload callback to reduce start latency on large tracks.
        if (useHtml5Streaming) {
            const queuedId = howl.play();
            if (typeof queuedId === 'number' && this.current?.howl === howl) {
                this.current.soundId = queuedId;
            }
        }
    }

    _pickRandomStartSec(durationSec) {
        const dur = Number(durationSec);
        if (!Number.isFinite(dur) || dur <= 0) return null;

        // Cap at 60% of the track OR duration - 60s tail,
        // whichever ceiling is lower. Guarantees we never start
        // within the final 60s.
        let max = Math.max(
            0,
            Math.min(dur * RANDOM_POS_MAX_FRACTION, dur - TAIL_PROTECTION_SEC),
        );

        if (max <= 0) return 0;
        return Math.random() * max;
    }

    _safeUnload(howl) {
        this._clearPendingMobileFade(howl);
        this._clearPendingFadeOut(howl);
        this._trackNormalizationGainByHowl.delete(howl);
        try { howl.unload(); } catch (_) { /* ignore */ }
    }

    _clearPendingFadeOut(howl) {
        const cleanup = this._pendingFadeOutCleanup.get(howl);
        if (!cleanup) return;
        this._pendingFadeOutCleanup.delete(howl);
        try { cleanup(); } catch (_) { /* ignore */ }
    }

    _fadeOutAndUnload(howl, soundId = null, fadeOutMs = FADE_OUT_MS) {
        this._clearPendingMobileFade(howl);
        this._clearPendingFadeOut(howl);
        try {
            let finished = false;
            let fallbackTimer = null;
            let waitForReadyTimer = null;
            const stopAndUnload = () => {
                if (finished) return;
                finished = true;
                this._clearPendingMobileFade(howl);
                this._clearPendingFadeOut(howl);
                try {
                    if (soundId != null) {
                        howl.stop(soundId);
                    } else {
                        howl.stop();
                    }
                    howl.unload();
                } catch (_) {
                    try { howl.unload(); } catch (__) { /* ignore */ }
                }
            };

            const onFade = (fadedId) => {
                if (soundId != null && typeof fadedId === 'number' && fadedId !== soundId) {
                    return;
                }
                stopAndUnload();
            };

            const cleanup = () => {
                if (fallbackTimer) {
                    clearTimeout(fallbackTimer);
                    fallbackTimer = null;
                }
                if (waitForReadyTimer) {
                    clearTimeout(waitForReadyTimer);
                    waitForReadyTimer = null;
                }
                try {
                    if (soundId != null) {
                        howl.off('fade', onFade, soundId);
                    } else {
                        howl.off('fade', onFade);
                    }
                } catch (_) { /* ignore */ }
            };

            this._pendingFadeOutCleanup.set(howl, cleanup);
            const canStartFadeNow = () => {
                if (finished) return false;
                const state = typeof howl.state === 'function' ? howl.state() : 'loaded';
                const isLoaded = state === 'loaded';
                // Howler can queue fade() while playback Promise lock is active.
                // On mobile this queue delay is noticeable; stopping by absolute
                // timer then cuts audio abruptly. Wait until lock clears first.
                const isPlayLocked = Boolean(howl._playLock);
                return isLoaded && !isPlayLocked;
            };

            const startFade = () => {
                const startVol = this._getTrackVolume(howl, soundId);
                const fadeStarted = this._fadeTrackVolume(
                    howl,
                    soundId,
                    startVol,
                    0,
                    fadeOutMs,
                    () => onFade(soundId),
                );
                if (!fadeStarted) {
                    stopAndUnload();
                    return;
                }
                fallbackTimer = setTimeout(stopAndUnload, fadeOutMs + 320);
            };

            const readyDeadline = Date.now() + Math.max(2600, fadeOutMs + 900);
            const waitUntilReady = () => {
                if (finished) return;
                if (canStartFadeNow()) {
                    startFade();
                    return;
                }
                if (Date.now() >= readyDeadline) {
                    stopAndUnload();
                    return;
                }
                waitForReadyTimer = setTimeout(waitUntilReady, 80);
            };

            waitUntilReady();
        } catch (_) {
            try { howl.unload(); } catch (__) { /* ignore */ }
        }
    }

    _cancelPauseTransition() {
        this._pauseOpToken += 1;
        if (this.current?.howl) {
            this._clearPendingMobileFade(this.current.howl);
        }
        if (this._pauseFadeTimer) {
            clearTimeout(this._pauseFadeTimer);
            this._pauseFadeTimer = null;
        }
    }

    _clearActiveState() {
        this.state.playingFolder = null;
        this.state.playingLabel = null;
        this.state.isPaused = false;
        this.state.canResume = false;
        this.state.pausedFolder = null;
        this.state.pausedLabel = null;
    }

    _startMasterVolumeSmoothing() {
        if (typeof window === 'undefined' || typeof window.requestAnimationFrame !== 'function') {
            this._applyMasterVolume(this._masterVolumeTarget);
            return;
        }
        if (this._masterVolumeRaf != null) return;

        const tick = () => {
            const delta = this._masterVolumeTarget - this._masterVolumeApplied;
            if (Math.abs(delta) <= MASTER_VOLUME_EPSILON) {
                this._applyMasterVolume(this._masterVolumeTarget);
                this._masterVolumeRaf = null;
                return;
            }
            const next = this._masterVolumeApplied + (delta * MASTER_VOLUME_SMOOTHING_FACTOR);
            this._applyMasterVolume(next);
            this._masterVolumeRaf = window.requestAnimationFrame(tick);
        };

        this._masterVolumeRaf = window.requestAnimationFrame(tick);
    }

    _applyMasterVolume(volume) {
        const next = this._clampVolume(volume);
        this._masterVolumeApplied = next;
        const useWebAudioMasterGain = this._shouldUseWebAudioMasterGain();
        try {
            Howler.volume(useWebAudioMasterGain ? 1 : next);
        } catch (_) { /* ignore */ }
        if (this._appleMobileMasterGain) {
            try {
                const ctx = this._appleMobileMasterGain.context;
                const now = ctx.currentTime;
                this._appleMobileMasterGain.gain.cancelScheduledValues(now);
                this._appleMobileMasterGain.gain.setValueAtTime(next, now);
            } catch (_) { /* ignore */ }
        }
    }

    _persistMasterVolume(volume) {
        if (typeof window === 'undefined' || !window.localStorage) return;
        try {
            window.localStorage.setItem(MASTER_VOLUME_STORAGE_KEY, String(volume));
        } catch (_) { /* ignore */ }
    }

    _readStoredMasterVolume() {
        if (typeof window === 'undefined' || !window.localStorage) return DEFAULT_MASTER_VOLUME;
        try {
            const raw = window.localStorage.getItem(MASTER_VOLUME_STORAGE_KEY);
            if (raw == null || raw === '') return DEFAULT_MASTER_VOLUME;
            const parsed = Number(raw);
            return this._clampVolume(parsed);
        } catch (_) {
            return DEFAULT_MASTER_VOLUME;
        }
    }

    _clampVolume(value) {
        const n = Number(value);
        if (!Number.isFinite(n)) return DEFAULT_MASTER_VOLUME;
        return Math.min(1, Math.max(0, n));
    }

    _clampTrackNormalizationGain(value) {
        const n = Number(value);
        if (!Number.isFinite(n)) return DEFAULT_TRACK_NORMALIZATION_GAIN;
        return Math.min(
            MAX_TRACK_NORMALIZATION_GAIN,
            Math.max(MIN_TRACK_NORMALIZATION_GAIN, n),
        );
    }

    _getTrackNormalizationGain(howl) {
        if (!howl) return DEFAULT_TRACK_NORMALIZATION_GAIN;
        const stored = this._trackNormalizationGainByHowl.get(howl);
        return this._clampTrackNormalizationGain(stored);
    }

    _resolveEffectiveTrackGain(howl, logicalVolume = 1) {
        const baseVolume = this._clampVolume(logicalVolume);
        const gain = this._getTrackNormalizationGain(howl);
        const effective = baseVolume * gain;
        if (!Number.isFinite(effective)) return baseVolume;
        return Math.max(0, Math.min(MAX_TRACK_NORMALIZATION_GAIN, effective));
    }

    _isAppleMobileDevice() {
        return this._isIphoneFamilyDevice() || this._isIpadDevice();
    }

    _isIphoneFamilyDevice() {
        if (typeof navigator === 'undefined') return false;
        try {
            const ua = navigator.userAgent || '';
            return /iPhone|iPod/i.test(ua);
        } catch (_) {
            return false;
        }
    }

    _isIpadDevice() {
        if (typeof navigator === 'undefined') return false;
        try {
            const ua = navigator.userAgent || '';
            return /iPad/i.test(ua)
                || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
        } catch (_) {
            return false;
        }
    }

    _shouldUseWebAudioMasterGain() {
        // Use dedicated WebAudio master-gain only on iPhone/iPod.
        // Desktop/iPad use native Howler master volume so live slider moves
        // affect an already-playing track immediately.
        return this._isIphoneFamilyDevice();
    }

    _shouldUseAppleMobileGainPath(howl) {
        return Boolean(
            howl
            // iPad Safari has shown instability with createMediaElementSource
            // on long streamed tracks. Keep native Howler fade path there.
            // Keep graph path only on iPhone/iPod where it solves html5 fade
            // quirks; desktop and iPad stay on native Howler volume path.
            && this._isIphoneFamilyDevice()
            && howl._webAudio === false,
        );
    }

    _ensureAppleMobileAudioContext() {
        if (this._appleMobileContext && this._appleMobileMasterGain) {
            return this._appleMobileContext;
        }
        if (typeof window === 'undefined') return null;
        const Ctx = window.AudioContext || window.webkitAudioContext;
        if (!Ctx) return null;
        try {
            const ctx = this._appleMobileContext || new Ctx();
            const master = this._appleMobileMasterGain || ctx.createGain();
            if (!this._appleMobileMasterGain) {
                master.connect(ctx.destination);
            }
            master.gain.setValueAtTime(this._masterVolumeApplied, ctx.currentTime);
            this._appleMobileContext = ctx;
            this._appleMobileMasterGain = master;
            return ctx;
        } catch (_) {
            return null;
        }
    }

    _resumeAppleMobileAudioContext() {
        const ctx = this._appleMobileContext || this._ensureAppleMobileAudioContext();
        if (!ctx || typeof ctx.resume !== 'function') return;
        if (ctx.state === 'running') return;
        try {
            const resumed = ctx.resume();
            if (resumed && typeof resumed.catch === 'function') {
                resumed.catch(() => {});
            }
        } catch (_) { /* ignore */ }
    }

    _resolveHowlSoundNode(howl, soundId = null) {
        if (!howl) return null;
        try {
            if (soundId != null && typeof howl._soundById === 'function') {
                const sound = howl._soundById(soundId);
                if (sound?._node) return sound._node;
            }
            if (Array.isArray(howl._sounds) && howl._sounds.length) {
                const first = howl._sounds.find((s) => s && s._node) || howl._sounds[0];
                if (first?._node) return first._node;
            }
        } catch (_) { /* ignore */ }
        return null;
    }

    _canUseMediaElementGraph(node) {
        if (!node || typeof window === 'undefined') return false;
        try {
            const src = String(node.currentSrc || node.src || '').trim();
            if (!src) return true;
            if (src.startsWith('blob:') || src.startsWith('data:')) return true;
            const parsed = new URL(src, window.location.href);
            return parsed.origin === window.location.origin;
        } catch (_) {
            return false;
        }
    }

    _ensureAppleMobileGraph(howl, soundId = null) {
        if (!this._shouldUseAppleMobileGainPath(howl)) return null;
        const ctx = this._ensureAppleMobileAudioContext();
        if (!ctx) return null;
        const node = this._resolveHowlSoundNode(howl, soundId);
        if (!node) return null;
        if (!this._canUseMediaElementGraph(node)) return null;

        let graph = this._appleMobileGraphByNode.get(node);
        if (!graph) {
            try {
                const source = ctx.createMediaElementSource(node);
                const gain = ctx.createGain();
                source.connect(gain);
                if (this._appleMobileMasterGain) {
                    gain.connect(this._appleMobileMasterGain);
                } else {
                    gain.connect(ctx.destination);
                }
                gain.gain.setValueAtTime(1, ctx.currentTime);
                graph = { node, source, gain };
                this._appleMobileGraphByNode.set(node, graph);
            } catch (_) {
                return null;
            }
        }

        this._appleMobileGraphByHowl.set(howl, graph);
        try { node.volume = 1; } catch (_) { /* ignore */ }
        this._resumeAppleMobileAudioContext();
        return graph;
    }

    _setAppleMobileGain(howl, soundId = null, value = 1) {
        const graph = this._ensureAppleMobileGraph(howl, soundId);
        if (!graph) return false;
        const v = this._resolveEffectiveTrackGain(howl, value);
        try {
            const now = graph.gain.context.currentTime;
            graph.gain.gain.cancelScheduledValues(now);
            graph.gain.gain.setValueAtTime(v, now);
            return true;
        } catch (_) {
            return false;
        }
    }

    _clearPendingMobileFade(howl) {
        const cleanup = this._pendingMobileFadeCleanup.get(howl);
        if (!cleanup) return;
        this._pendingMobileFadeCleanup.delete(howl);
        try { cleanup(); } catch (_) { /* ignore */ }
    }

    _resolveHowlerNodeVolume(howl, logicalVolume = 1) {
        const effectiveTrackGain = this._resolveEffectiveTrackGain(howl, logicalVolume);
        const master = this._shouldUseWebAudioMasterGain()
            ? this._masterVolumeApplied
            : 1;
        const combined = effectiveTrackGain * master;
        if (!Number.isFinite(combined)) {
            return this._clampVolume(logicalVolume);
        }
        return this._clampVolume(Math.min(1, Math.max(0, combined)));
    }

    _getTrackVolume(howl, soundId = null) {
        const graph = this._appleMobileGraphByHowl.get(howl)
            || this._ensureAppleMobileGraph(howl, soundId);
        if (graph) {
            try {
                const gain = this._getTrackNormalizationGain(howl);
                const raw = Number(graph.gain.gain.value);
                if (!Number.isFinite(raw)) return this._clampVolume(1);
                return this._clampVolume(raw / gain);
            } catch (_) { /* ignore */ }
        }
        try {
            const raw = this._clampVolume(soundId != null ? howl.volume(soundId) : howl.volume());
            const gain = this._getTrackNormalizationGain(howl);
            const master = this._shouldUseWebAudioMasterGain()
                ? Math.max(MASTER_VOLUME_EPSILON, this._masterVolumeApplied)
                : 1;
            return this._clampVolume(raw / (gain * master));
        } catch (_) {
            return 1;
        }
    }

    _setTrackVolumeImmediate(howl, soundId = null, value = 1) {
        const v = this._clampVolume(value);
        if (this._setAppleMobileGain(howl, soundId, v)) {
            return true;
        }
        try {
            const fallbackVolume = this._resolveHowlerNodeVolume(howl, v);
            if (soundId != null) {
                howl.volume(fallbackVolume, soundId);
            } else {
                howl.volume(fallbackVolume);
            }
            return true;
        } catch (_) {
            return false;
        }
    }

    _stopTrackFade(howl, soundId = null) {
        this._clearPendingMobileFade(howl);
        try {
            if (soundId != null && typeof howl._stopFade === 'function') {
                howl._stopFade(soundId);
            }
        } catch (_) { /* ignore */ }
    }

    _fadeTrackVolume(howl, soundId = null, from, to, durationMs, onComplete = null) {
        const start = this._clampVolume(from);
        const end = this._clampVolume(to);
        const duration = Math.max(0, Number(durationMs) || 0);
        this._stopTrackFade(howl, soundId);

        const graph = this._ensureAppleMobileGraph(howl, soundId);
        if (graph) {
            let completionTimer = null;
            const cleanup = () => {
                if (completionTimer) {
                    clearTimeout(completionTimer);
                    completionTimer = null;
                }
            };
            this._pendingMobileFadeCleanup.set(howl, cleanup);
            try {
                const startGain = this._resolveEffectiveTrackGain(howl, start);
                const endGain = this._resolveEffectiveTrackGain(howl, end);
                const now = graph.gain.context.currentTime;
                graph.gain.gain.cancelScheduledValues(now);
                graph.gain.gain.setValueAtTime(startGain, now);
                graph.gain.gain.linearRampToValueAtTime(endGain, now + (duration / 1000));
            } catch (_) {
                this._clearPendingMobileFade(howl);
                return false;
            }
            completionTimer = setTimeout(() => {
                const activeCleanup = this._pendingMobileFadeCleanup.get(howl);
                if (activeCleanup !== cleanup) return;
                try {
                    const endGain = this._resolveEffectiveTrackGain(howl, end);
                    const now = graph.gain.context.currentTime;
                    graph.gain.gain.cancelScheduledValues(now);
                    graph.gain.gain.setValueAtTime(endGain, now);
                } catch (_) { /* ignore */ }
                this._clearPendingMobileFade(howl);
                if (typeof onComplete === 'function') {
                    onComplete();
                }
            }, duration + APPLE_MOBILE_FADE_SETTLE_MS);
            return true;
        }

        let completionTimer = null;
        let onFade = null;
        const cleanup = () => {
            if (completionTimer) {
                clearTimeout(completionTimer);
                completionTimer = null;
            }
            if (!onFade) return;
            try {
                if (soundId != null) {
                    howl.off('fade', onFade, soundId);
                } else {
                    howl.off('fade', onFade);
                }
            } catch (_) { /* ignore */ }
        };
        if (typeof onComplete === 'function') {
            onFade = (fadedId) => {
                if (soundId != null && typeof fadedId === 'number' && fadedId !== soundId) {
                    return;
                }
                this._clearPendingMobileFade(howl);
                onComplete();
            };
            this._pendingMobileFadeCleanup.set(howl, cleanup);
            try {
                if (soundId != null) {
                    howl.once('fade', onFade, soundId);
                } else {
                    howl.once('fade', onFade);
                }
            } catch (_) { /* ignore */ }
            completionTimer = setTimeout(() => {
                const activeCleanup = this._pendingMobileFadeCleanup.get(howl);
                if (activeCleanup !== cleanup) return;
                this._clearPendingMobileFade(howl);
                onComplete();
            }, Math.max(MOBILE_FADE_START_FALLBACK_MS, duration + APPLE_MOBILE_FADE_SETTLE_MS));
        }

        try {
            const startFallback = this._resolveHowlerNodeVolume(howl, start);
            const endFallback = this._resolveHowlerNodeVolume(howl, end);
            if (soundId != null) {
                howl.fade(startFallback, endFallback, duration, soundId);
            } else {
                howl.fade(startFallback, endFallback, duration);
            }
            return true;
        } catch (_) {
            this._clearPendingMobileFade(howl);
            return false;
        }
    }

    _fadeInWhenPlaybackStabilizes(howl, soundId, durationMs) {
        this._fadeTrackVolume(howl, soundId ?? null, 0, 1, durationMs);
    }
}

export const engine = new AudioEngine();
