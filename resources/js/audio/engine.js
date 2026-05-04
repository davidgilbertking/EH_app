import { Howl } from 'howler';
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
const FADE_IN_MS = 3000;
const PAUSE_TOGGLE_FADE_MS = 1250;
const RANDOM_POS_MAX_FRACTION = 0.6;
const MOBILE_FADE_START_FALLBACK_MS = 1500;
const MOBILE_FADE_INTERVAL_MS = 50;
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
        this._pendingMobileFadeCleanup = new WeakMap();
        // Reactive surface for Vue components to bind to.
        this.state = reactive({
            playingFolder: null, // folder slug currently active (or null)
            playingLabel: null,
            isLoading: false,
            isPaused: false,
            canResume: false,
            pausedFolder: null, // folder slug paused via corner pause button
            pausedLabel: null,
        });
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
    async play({ folderSlug, mode, label, hardSwitch = false, crossfade = false }) {
        if (!folderSlug) return;
        const requestToken = ++this._playRequestToken;
        this._cancelPauseTransition();

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
                this._fadeOutAndUnload(prev.howl, prev.soundId ?? null);
                if (hardSwitch) {
                    await new Promise((r) => setTimeout(r, FADE_OUT_MS + 80));
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
                prevHowlForCrossfade,
                requestToken,
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
            const startVol = soundId != null ? howl.volume(soundId) : howl.volume();
            if (soundId != null) {
                howl.fade(startVol, 0, PAUSE_TOGGLE_FADE_MS, soundId);
            } else {
                howl.fade(startVol, 0, PAUSE_TOGGLE_FADE_MS);
            }
            this._pauseFadeTimer = setTimeout(() => {
                if (opToken !== this._pauseOpToken) return;
                try {
                    if (soundId != null) {
                        howl.pause(soundId);
                        // Keep at 0 so resume can fade in from silence.
                        howl.volume(0, soundId);
                    } else {
                        howl.pause();
                        // Keep at 0 so resume can fade in from silence.
                        howl.volume(0);
                    }
                } catch (_) { /* ignore */ }
                this._pauseFadeTimer = null;
            }, PAUSE_TOGGLE_FADE_MS + 30);
            return true;
        } catch (_) {
            try {
                if (soundId != null) {
                    howl.pause(soundId);
                    howl.volume(0, soundId);
                } else {
                    howl.pause();
                    howl.volume(0);
                }
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
            if (soundId != null && typeof howl._stopFade === 'function') {
                howl._stopFade(soundId);
            }

            const fadeIn = (idHint = null) => {
                try {
                    if (!this.current || this.current.howl !== howl) return;
                    const id = this.current.soundId ?? idHint ?? null;
                    const startVol = id != null ? howl.volume(id) : howl.volume();
                    if (startVol >= 1) return;
                    if (id != null) {
                        howl.fade(startVol, 1, PAUSE_TOGGLE_FADE_MS, id);
                    } else {
                        howl.fade(startVol, 1, PAUSE_TOGGLE_FADE_MS);
                    }
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
        prevHowlForCrossfade = null,
        requestToken,
    }) {
        const useFadeIn = mode !== MODE_FROM_START_NO_FADE;
        const useRandomPos = mode !== MODE_FROM_START_NO_FADE;
        // iOS + HTML5 audio has inconsistent programmatic volume control, which
        // breaks fade-in/fade-out on iPhone Chrome/Safari. Use WebAudio there.
        const useHtml5Stream = !(this._isPhoneLikeDevice() && this._isIOSDevice());

        // seekApplied guards against the onplay handler firing more than once
        // per Howl (e.g. on loop or re-seek) and re-randomising the position.
        let seekApplied = false;

        const howl = new Howl({
            src: [streamUrl],
            // Stream URL has no file extension; Howler/browsers can't sniff codec
            // from URL alone. Pass the format hint from the server.
            format: format ? [format] : undefined,
            html5: useHtml5Stream,
            volume: useFadeIn ? 0 : 1,
            onload: () => {
                if (
                    requestToken !== this._playRequestToken
                    || this.current?.howl !== howl
                ) {
                    this._safeUnload(howl);
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
                    try { howl.stop(id); } catch (_) { /* ignore */ }
                    this._safeUnload(howl);
                    return;
                }
                if (this.current?.howl === howl && typeof id === 'number') {
                    this.current.soundId = id;
                }
                if (!seekApplied && useRandomPos) {
                    seekApplied = true;
                    const dur = durationSec || howl.duration() || 0;
                    if (dur > 0) {
                        // Cap at 60% of the track OR duration - 60s tail,
                        // whichever ceiling is lower. Guarantees we never start
                        // within the final 60s.
                        const max = Math.max(
                            0,
                            Math.min(dur * RANDOM_POS_MAX_FRACTION, dur - TAIL_PROTECTION_SEC),
                        );
                        const pos = Math.random() * max;
                        try { howl.seek(pos); } catch (_) { /* ignore */ }
                    }
                }
                const activeId = this.current?.howl === howl
                    ? (this.current.soundId ?? null)
                    : null;
                const currentVol = activeId != null ? howl.volume(activeId) : howl.volume();
                if (useFadeIn && currentVol < 1) {
                    const fadeId = activeId ?? (typeof id === 'number' ? id : null);
                    this._fadeInWhenPlaybackStabilizes(howl, fadeId, FADE_IN_MS);
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

        // Any previous track was already faded out in play() before the fetch,
        // so we don't need to crossfade here.
        this.current = { howl, soundId: null, folderSlug, label, mode };
        this.state.playingFolder = folderSlug;
        this.state.playingLabel = label;
        this.state.isPaused = false;
        this.state.canResume = true;
        this.state.pausedFolder = null;
        this.state.pausedLabel = null;
    }

    _safeUnload(howl) {
        this._clearPendingMobileFade(howl);
        try { howl.unload(); } catch (_) { /* ignore */ }
    }

    _fadeOutAndUnload(howl, soundId = null) {
        this._clearPendingMobileFade(howl);
        try {
            const startVol = soundId != null ? howl.volume(soundId) : howl.volume();
            if (soundId != null) {
                howl.fade(startVol, 0, FADE_OUT_MS, soundId);
            } else {
                howl.fade(startVol, 0, FADE_OUT_MS);
            }
            setTimeout(() => {
                try {
                    if (soundId != null) {
                        howl.stop(soundId);
                    } else {
                        howl.stop();
                    }
                    howl.unload();
                } catch (_) { /* already unloaded */ }
            }, FADE_OUT_MS + 200);
        } catch (_) {
            try { howl.unload(); } catch (__) { /* ignore */ }
        }
    }

    _cancelPauseTransition() {
        this._pauseOpToken += 1;
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

    _isPhoneLikeDevice() {
        if (typeof window === 'undefined') return false;
        try {
            const isTouchPrimary = window.matchMedia?.('(pointer: coarse)').matches ?? false;
            const isNarrowTouch = isTouchPrimary
                && (window.matchMedia?.('(max-width: 900px)').matches ?? false);
            const ua = typeof navigator !== 'undefined' ? (navigator.userAgent || '') : '';
            const looksLikeMobileUa = /Android|webOS|iPhone|iPod|BlackBerry|IEMobile|Opera Mini|Mobile/i.test(ua);
            const isIpad = /iPad/i.test(ua)
                || (
                    typeof navigator !== 'undefined'
                    && navigator.platform === 'MacIntel'
                    && navigator.maxTouchPoints > 1
                );
            return isNarrowTouch || (looksLikeMobileUa && !isIpad);
        } catch (_) {
            return false;
        }
    }

    _isIOSDevice() {
        if (typeof navigator === 'undefined') return false;
        const ua = navigator.userAgent || '';
        if (/iPhone|iPod|iPad/i.test(ua)) return true;
        // iPadOS in desktop mode.
        return navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1;
    }

    _clearPendingMobileFade(howl) {
        const cleanup = this._pendingMobileFadeCleanup.get(howl);
        if (!cleanup) return;
        this._pendingMobileFadeCleanup.delete(howl);
        try { cleanup(); } catch (_) { /* ignore */ }
    }

    _fadeInWhenPlaybackStabilizes(howl, soundId, durationMs) {
        const runNativeFade = (id = null) => {
            try {
                if (id != null) {
                    howl.fade(0, 1, durationMs, id);
                } else {
                    howl.fade(0, 1, durationMs);
                }
            } catch (_) { /* ignore */ }
        };

        if (!this._isPhoneLikeDevice()) {
            runNativeFade(soundId ?? null);
            return;
        }

        const sound = (typeof howl._soundById === 'function' && soundId != null)
            ? howl._soundById(soundId)
            : null;
        const node = sound?._node;
        if (!node || typeof node.addEventListener !== 'function') {
            runNativeFade(soundId ?? null);
            return;
        }

        this._clearPendingMobileFade(howl);
        let started = false;
        let fallbackTimer = null;
        let rampTimer = null;

        const getActiveId = () => (this.current?.howl === howl
            ? (this.current.soundId ?? soundId ?? null)
            : (soundId ?? null));

        const applyVolume = (id, value) => {
            try {
                if (id != null) {
                    howl.volume(value, id);
                } else {
                    howl.volume(value);
                }
            } catch (_) { /* ignore */ }
        };

        const tearDown = () => {
            try { node.removeEventListener('timeupdate', onTimeUpdate); } catch (_) { /* ignore */ }
            if (fallbackTimer) {
                clearTimeout(fallbackTimer);
                fallbackTimer = null;
            }
            if (rampTimer) {
                clearInterval(rampTimer);
                rampTimer = null;
            }
        };

        const finish = () => {
            tearDown();
            this._pendingMobileFadeCleanup.delete(howl);
        };

        const startManualFade = () => {
            if (started) return;
            started = true;
            if (!this.current || this.current.howl !== howl) {
                finish();
                return;
            }
            const activeId = getActiveId();
            applyVolume(activeId, 0);
            const startedAt = Date.now();
            rampTimer = setInterval(() => {
                if (!this.current || this.current.howl !== howl) {
                    finish();
                    return;
                }
                const id = getActiveId();
                const elapsed = Date.now() - startedAt;
                const nextVol = Math.min(1, Math.max(0, elapsed / durationMs));
                applyVolume(id, nextVol);
                if (nextVol >= 1) {
                    finish();
                }
            }, MOBILE_FADE_INTERVAL_MS);
        };

        const startFade = () => {
            if (!this.current || this.current.howl !== howl) return;
            startManualFade();
        };

        const onTimeUpdate = () => {
            startFade();
        };

        try {
            node.addEventListener('timeupdate', onTimeUpdate, { once: true });
        } catch (_) {
            runNativeFade(soundId ?? null);
            return;
        }

        fallbackTimer = setTimeout(startFade, MOBILE_FADE_START_FALLBACK_MS);
        this._pendingMobileFadeCleanup.set(howl, tearDown);
    }
}

export const engine = new AudioEngine();
