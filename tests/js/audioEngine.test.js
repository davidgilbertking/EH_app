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
