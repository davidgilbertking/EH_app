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
const PAUSE_TOGGLE_FADE_MS = 1000;
const RANDOM_POS_MAX_FRACTION = 0.6;
// Never start a random-pos track inside the final TAIL_PROTECTION_SEC seconds.
// Assumption: no tracks are shorter than this value.
const TAIL_PROTECTION_SEC = 60;

export const MODE_RANDOM_POS_FADE = 'random_pos_fade';
export const MODE_FROM_START_NO_FADE = 'from_start_no_fade';

class AudioEngine {
    constructor() {
        this.current = null; // { howl, folderSlug, label }
        this._pauseFadeTimer = null;
        this._pauseOpToken = 0;
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
        this._cancelPauseTransition();

        // If there is a paused track and user explicitly starts another folder,
        // discard paused buffer and treat this as a brand new playback choice.
        if (this.current && this.state.isPaused) {
            try {
                this.current.howl.stop();
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
                prevHowlForCrossfade = this.current.howl;
                // Detach so a second re-entrant play() during the network
                // fetch doesn't think there's still a "current" to handle.
                // The Howl itself keeps playing until we fade it out below.
                this.current = null;
            } else {
                const prev = this.current.howl;
                this.current = null;
                this._clearActiveState();
                this._fadeOutAndUnload(prev);
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
            if (!res.ok) {
                console.warn('[engine] pick failed', folderSlug, res.status);
                this.state.isLoading = false;
                this._clearActiveState();
                // If we promised a crossfade and never started a new track,
                // still kill the old one so we don't leak a Howl.
                if (prevHowlForCrossfade) this._fadeOutAndUnload(prevHowlForCrossfade);
                return;
            }
            const pick = await res.json();
            const effectiveMode = mode || pick.mode || MODE_RANDOM_POS_FADE;

            this._startTrack({
                streamUrl: pick.streamUrl,
                durationSec: pick.durationSec,
                mode: effectiveMode,
                folderSlug,
                label: label || pick.folderName || folderSlug,
                format: pick.format || null,
                prevHowlForCrossfade,
            });
        } catch (e) {
            console.error('[engine] play error', e);
            this._clearActiveState();
            if (prevHowlForCrossfade) this._fadeOutAndUnload(prevHowlForCrossfade);
        } finally {
            this.state.isLoading = false;
        }
    }

    /** Stop the currently-playing track with fade-out. */
    stop() {
        this._cancelPauseTransition();
        if (!this.current) return;
        if (this.state.isPaused) {
            try {
                this.current.howl.stop();
                this.current.howl.unload();
            } catch (_) { /* ignore */ }
        } else {
            this._fadeOutAndUnload(this.current.howl);
        }
        this.current = null;
        this._clearActiveState();
    }

    pause() {
        if (!this.current || this.state.isPaused) return false;

        this._cancelPauseTransition();
        const opToken = ++this._pauseOpToken;
        const howl = this.current.howl;

        // Reflect paused state in UI immediately while audio fades out.
        this.state.isPaused = true;
        this.state.playingFolder = null;
        this.state.playingLabel = null;
        this.state.canResume = true;
        this.state.pausedFolder = this.current.folderSlug;
        this.state.pausedLabel = this.current.label;

        try {
            const startVol = howl.volume();
            howl.fade(startVol, 0, PAUSE_TOGGLE_FADE_MS);
            this._pauseFadeTimer = setTimeout(() => {
                if (opToken !== this._pauseOpToken) return;
                try {
                    howl.pause();
                    // Keep at 0 so resume can fade in from silence.
                    howl.volume(0);
                } catch (_) { /* ignore */ }
                this._pauseFadeTimer = null;
            }, PAUSE_TOGGLE_FADE_MS + 30);
            return true;
        } catch (_) {
            try {
                howl.pause();
                howl.volume(0);
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

        try {
            if (!howl.playing()) {
                howl.play();
            }

            const startVol = howl.volume();
            howl.fade(startVol, 1, PAUSE_TOGGLE_FADE_MS);

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
    _startTrack({ streamUrl, durationSec, mode, folderSlug, label, format, prevHowlForCrossfade = null }) {
        const useFadeIn = mode !== MODE_FROM_START_NO_FADE;
        const useRandomPos = mode !== MODE_FROM_START_NO_FADE;

        // seekApplied guards against the onplay handler firing more than once
        // per Howl (e.g. on loop or re-seek) and re-randomising the position.
        let seekApplied = false;

        const howl = new Howl({
            src: [streamUrl],
            // Stream URL has no file extension; Howler/browsers can't sniff codec
            // from URL alone. Pass the format hint from the server.
            format: format ? [format] : undefined,
            html5: true, // stream from URL; required for files > a few MB
            volume: useFadeIn ? 0 : 1,
            onload: () => {
                // Kick off playback as soon as metadata loads. Seeking and fading
                // are deferred to `onplay` because, with html5:true, calling
                // seek() before the audio element has actually started playing
                // silently fails in some browsers (Chrome especially).
                howl.play();
            },
            onplay: () => {
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
                if (useFadeIn && howl.volume() < 1) {
                    howl.fade(0, 1, FADE_IN_MS);
                }
                // Crossfade: only NOW (when the new track is actually audible)
                // do we start fading the previous one. Guarantees no silent
                // gap when the user re-taps a blob or switches between blobs.
                if (prevHowlForCrossfade) {
                    this._fadeOutAndUnload(prevHowlForCrossfade);
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
                // Happens when the browser blocks autoplay. Retry once the user
                // interacts again (Howler's built-in 'unlock' event).
                howl.once('unlock', () => howl.play());
            },
            onloaderror: (id, err) => {
                console.warn('[engine] load error', err);
            },
        });

        // Any previous track was already faded out in play() before the fetch,
        // so we don't need to crossfade here.
        this.current = { howl, folderSlug, label };
        this.state.playingFolder = folderSlug;
        this.state.playingLabel = label;
        this.state.isPaused = false;
        this.state.canResume = true;
        this.state.pausedFolder = null;
        this.state.pausedLabel = null;
    }

    _fadeOutAndUnload(howl) {
        try {
            const startVol = howl.volume();
            howl.fade(startVol, 0, FADE_OUT_MS);
            setTimeout(() => {
                try {
                    howl.stop();
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
}

export const engine = new AudioEngine();
