import test from 'node:test';
import assert from 'node:assert/strict';
import { engine } from '../../resources/js/audio/engine.js';

function setupGraph({ iphone = true, initialVolume = 0, normalizationGain = 1 } = {}) {
    const audio = new engine.constructor();
    const node = { volume: initialVolume };
    let storedVolume = initialVolume;
    const soundId = 42;
    const howl = {
        _webAudio: false,
        _sounds: [{ _id: soundId, _node: node }],
        _soundById: () => ({ _node: node }),
        volume(value, id) {
            if (arguments.length < 2) return storedVolume;
            assert.equal(id, soundId);
            storedVolume = value;
            node.volume = value;
            return this;
        },
        // Howler reapplies its own stored volume when restarting after seek.
        seekAndResume() { node.volume = storedVolume; },
    };
    const ctx = {
        state: 'running', currentTime: 0,
        createMediaElementSource: () => ({ connect() {} }),
        createGain: () => ({
            context: ctx, connect() {},
            gain: { value: 1, cancelScheduledValues() {}, setValueAtTime(value) { this.value = value; } },
        }),
    };
    audio._isIphoneFamilyDevice = () => iphone;
    audio._canUseMediaElementGraph = () => true;
    audio._ensureAppleMobileAudioContext = () => ctx;
    audio._trackNormalizationGainByHowl.set(howl, normalizationGain);
    return { audio, howl, node, soundId };
}

test('iPhone graph keeps the source audible after Howler seeks a fade-in track', () => {
    const { audio, howl, node, soundId } = setupGraph();
    audio._setTrackVolumeImmediate(howl, soundId, 0);
    howl.seekAndResume();
    audio._setTrackVolumeImmediate(howl, soundId, 1);
    howl.seekAndResume();
    assert.equal(node.volume, 1);
    assert.equal(audio._appleMobileGraphByHowl.get(howl).gain.gain.value, 1);
});

test('iPhone fades and normalization stay on the graph without changing source volume', () => {
    const { audio, howl, node, soundId } = setupGraph({ normalizationGain: 0.5 });
    audio._setTrackVolumeImmediate(howl, soundId, 0.4);
    assert.equal(audio._appleMobileGraphByHowl.get(howl).gain.gain.value, 0.2);
    howl.seekAndResume();
    assert.equal(node.volume, 1);
    audio._setTrackVolumeImmediate(howl, soundId, 0);
    assert.equal(audio._appleMobileGraphByHowl.get(howl).gain.gain.value, 0);
    assert.equal(node.volume, 1);
});

test('Android and desktop retain the native Howler volume path', () => {
    const { audio, howl, node, soundId } = setupGraph({ iphone: false });
    audio._setTrackVolumeImmediate(howl, soundId, 0.4);
    howl.seekAndResume();
    assert.equal(node.volume, 0.4);
    assert.equal(audio._appleMobileGraphByHowl.has(howl), false);
});

function setupContinuation({ pickTrackId, mode = 'from_start_no_fade' }) {
    const audio = new engine.constructor();
    const calls = [];
    const howl = {
        duration: () => 300,
        seek(sec, id) { calls.push(['seek', sec, id]); return this; },
        play(id) { calls.push(['play', id]); return id; },
    };
    const track = { howl, soundId: 7, trackId: 11, folderSlug: 'special/characters/agnes-baker', label: 'Agnes', mode };
    audio.current = track;
    audio._setTrackVolumeImmediate = (_howl, _id, value) => calls.push(['volume', value]);
    const picks = [];
    audio._fetchPick = async (...args) => {
        picks.push(args);
        return { trackId: pickTrackId, streamUrl: `/audio/track/${pickTrackId}/stream` };
    };
    const played = [];
    audio.play = (opts) => { played.push(opts); };
    return { audio, track, calls, played, picks };
}

test('auto-continue tells the server which track ended so split pieces continue', async () => {
    const { audio, track, picks, played } = setupContinuation({ pickTrackId: 12 });
    await audio._continueFolder(track);
    assert.deepEqual(picks, [['special/characters/agnes-baker', 11]]);
    assert.equal(played[0].pick.trackId, 12);
});

test('pick requests add the ended track id only for auto-continue', async (t) => {
    const audio = new engine.constructor();
    const urls = [];
    t.mock.method(globalThis, 'fetch', async (url) => {
        urls.push(url);
        return { ok: true, json: async () => ({ trackId: 1 }) };
    });
    await audio._fetchPick('special/characters/vincent-lee');
    await audio._fetchPick('special/characters/vincent-lee', 1253);
    assert.deepEqual(urls, [
        '/audio/folder/special%2Fcharacters%2Fvincent-lee/random',
        '/audio/folder/special%2Fcharacters%2Fvincent-lee/random?after=1253',
    ]);
});

test('auto-continue replays the ended element when the same track is picked again', async () => {
    const { audio, track, calls, played } = setupContinuation({ pickTrackId: 11 });
    await audio._continueFolder(track);
    assert.deepEqual(calls, [['seek', 0, 7], ['play', 7]]);
    assert.equal(played.length, 0);
});

test('auto-continue fades random-position folders back in when replaying the same track', async () => {
    const { audio, track, calls } = setupContinuation({ pickTrackId: 11, mode: 'random_pos_fade' });
    await audio._continueFolder(track);
    assert.deepEqual(calls[0], ['volume', 0]);
    assert.equal(calls[1][0], 'seek');
    assert.ok(calls[1][1] >= 0 && calls[1][1] <= 180);
    assert.deepEqual(calls[2], ['play', 7]);
});

test('auto-continue starts a new track with the already-fetched pick', async () => {
    const { audio, track, calls, played } = setupContinuation({ pickTrackId: 12 });
    await audio._continueFolder(track);
    assert.equal(calls.length, 0);
    assert.equal(played.length, 1);
    assert.equal(played[0].folderSlug, track.folderSlug);
    assert.equal(played[0].pick.trackId, 12);
});

test('auto-continue is cancelled when the user switched music during the pick', async () => {
    const { audio, track, calls, played } = setupContinuation({ pickTrackId: 11 });
    const pending = audio._continueFolder(track);
    audio._playRequestToken += 1;
    await pending;
    assert.equal(calls.length, 0);
    assert.equal(played.length, 0);
});
