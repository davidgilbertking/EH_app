import test from 'node:test';
import assert from 'node:assert/strict';
import { createGameFlowController, createSessionId, resolveGameContext } from '../../resources/js/gameFlow/createController.js';
import { useLongPress } from '../../resources/js/composables/useLongPress.js';

function setup(overrides = {}) {
    let time = 0;
    let session = 0;
    const events = [];
    const audio = {
        state: { playingFolder: null, isPaused: false, isLoading: false },
        play(options) { events.push(['play', options]); this.state.playingFolder = options.folderSlug; },
        stop() { events.push(['stop']); this.state.playingFolder = null; },
        pause() { events.push(['pause']); this.state.isPaused = true; },
        resume() { events.push(['resume']); this.state.isPaused = false; },
        fadeOutCurrent() { events.push(['fade']); return Promise.resolve(); },
    };
    const lighting = {
        requestTarget(target) { events.push(['light', target]); },
        releaseForLogout() { events.push(['release']); return Promise.resolve(); },
    };
    const flow = createGameFlowController({
        audio, lighting, canControlLighting: () => true, now: () => time, uuid: () => `session-${++session}`,
        navigate: (url) => events.push(['navigate', url]), ...overrides,
    });
    return { flow, audio, lighting, events, advance: (ms = 300) => { time += ms; },
        lights: () => events.filter(([type]) => type === 'light').map(([, target]) => target),
        music: () => events.filter(([type]) => ['play', 'stop', 'pause', 'resume'].includes(type)) };
}

test('Mythos starts audio synchronously, then darkness and navigation without waiting on either transport', async () => {
    const { flow, events, audio, lighting } = setup();
    audio.play = (options) => { events.push(['play', options]); return new Promise(() => {}); };
    lighting.requestTarget = (target) => { events.push(['light', target]); return new Promise(() => {}); };
    assert.equal(flow.enterMythos(), true);
    assert.deepEqual(events.map(([type]) => type), ['play', 'light', 'navigate']);
    assert.deepEqual(events[0][1], { folderSlug: 'mythos', label: 'Mythos', crossfade: true });
    assert.deepEqual(events[1][1], { kind: 'mythos', mythosSessionId: 'session-1', color: null });
    assert.equal(flow.state.selectedMythosColor, null);
});

test('without lighting permission Mythos toggles only music and stays on the current page', () => {
    const { flow, events, advance } = setup({ canControlLighting: () => false, initialUrl: '/encounters/general/city' });
    assert.equal(flow.enterMythos(), true);
    assert.deepEqual(events, [['play', { folderSlug: 'mythos', label: 'Mythos', crossfade: true }]]);
    assert.equal(flow.state.mythosSessionId, null);
    assert.equal(flow.selectMythosColor('blue', 'stale-session'), false);
    advance();
    flow.enterMythos();
    assert.deepEqual(events.at(-1), ['stop']);
    advance();
    flow.playUserChoice({ folderSlug: 'mythos', label: 'Mythos', mode: 'saved' });
    assert.equal(events.at(-1)[0], 'play');
    assert.equal(events.at(-1)[1].mode, 'saved');
    assert.deepEqual(events.map(([type]) => type), ['play', 'stop', 'play']);
});

test('all music controls and logout still work without any lighting calls for other users', async () => {
    const { flow, events, advance, music } = setup({ canControlLighting: () => false });
    flow.selectAction();
    flow.selectAction('action-muted');
    flow.selectCombat();
    flow.selectCombat('combat-epic');
    flow.playUserChoice({ folderSlug: 'contacts/city', label: 'City' });
    flow.playUserChoice({ folderSlug: 'ancient/test', label: 'Other' }, 'other');
    flow.enterMythos();
    flow.togglePause();
    advance();
    flow.togglePause();
    flow.stopUserAudio();
    const { fadePromise, releasePromise } = flow.logout();
    await Promise.all([fadePromise, releasePromise]);
    assert.equal(music().length, 10);
    assert.equal(events.at(-1)[0], 'fade');
    assert.equal(events.some(([type]) => ['light', 'release', 'navigate'].includes(type)), false);
});

test('permission is required explicitly and revocation rejects an already selected color session', () => {
    const defaultEvents = [];
    const defaultFlow = createGameFlowController({
        audio: { state: {}, play: () => defaultEvents.push('play') },
        lighting: { requestTarget: () => defaultEvents.push('light') },
        navigate: () => defaultEvents.push('navigate'),
    });
    defaultFlow.enterMythos();
    assert.deepEqual(defaultEvents, ['play']);

    let allowed = true;
    const { flow, events, advance } = setup({ canControlLighting: () => allowed });
    flow.enterMythos();
    const sessionId = flow.state.mythosSessionId;
    allowed = false;
    events.length = 0;
    assert.equal(flow.selectMythosColor('blue', sessionId), false);
    advance();
    flow.enterMythos();
    assert.equal(flow.state.mythosSessionId, null);
    assert.deepEqual(events, [['stop']]);
    allowed = true;
    advance();
    events.length = 0;
    flow.enterMythos();
    assert.equal(flow.state.mythosSessionId, 'session-2');
    assert.deepEqual(events.map(([type]) => type), ['play', 'light', 'navigate']);
});

test('accidental duplicate filters audio, lighting and navigation as one action', () => {
    const { flow, events, advance } = setup();
    flow.enterMythos();
    advance(249);
    assert.equal(flow.enterMythos(), false);
    assert.equal(events.length, 3);
    advance(1);
    assert.equal(flow.enterMythos(), true);
    assert.equal(events[3][0], 'stop');
});

test('rapid distinct choices and a return to an earlier choice are all accepted', () => {
    const { flow, lights, music } = setup();
    flow.enterMythos();
    const session = flow.state.mythosSessionId;
    flow.selectMythosColor('green', session);
    flow.selectMythosColor('blue', session);
    flow.selectMythosColor('green', session);
    assert.equal(flow.selectMythosColor('green', session), false);
    assert.deepEqual(lights().map((target) => target.color), [null, 'green', 'blue', 'green']);
    assert.equal(music().length, 1);
    assert.equal(flow.state.selectedMythosColor, 'green');
});

test('color selection never plays, stops, resumes, changes volume or navigates', () => {
    const { flow, events } = setup();
    flow.enterMythos();
    events.length = 0;
    flow.selectMythosColor('yellow', flow.state.mythosSessionId);
    assert.deepEqual(events, [['light', { kind: 'mythos', mythosSessionId: 'session-1', color: 'yellow' }]]);
});

test('deliberate Mythos stop and restart preserve current session and color', () => {
    const { flow, advance, lights, music } = setup();
    flow.enterMythos();
    flow.observeNavigation('/mythos');
    flow.selectMythosColor('blue', flow.state.mythosSessionId);
    advance();
    flow.enterMythos();
    assert.equal(music().at(-1)[0], 'stop');
    assert.equal(flow.state.selectedMythosColor, 'blue');
    advance();
    flow.enterMythos();
    assert.equal(music().at(-1)[0], 'play');
    assert.equal(flow.state.mythosSessionId, 'session-1');
    assert.equal(lights().length, 2);
});

test('Action immediately invalidates old color events without navigating away', () => {
    const { flow, lights, events } = setup();
    flow.enterMythos();
    flow.observeNavigation('/mythos');
    const oldSession = flow.state.mythosSessionId;
    flow.selectMythosColor('blue', oldSession);
    events.length = 0;
    flow.selectAction();
    assert.equal(flow.state.mythosSessionId, null);
    assert.equal(flow.selectMythosColor('yellow', oldSession), false);
    assert.deepEqual(lights(), [{ kind: 'white', profile: 'action' }]);
    assert.equal(events.some(([type]) => type === 'navigate'), false);
    flow.enterMythos();
    assert.equal(flow.state.mythosSessionId, 'session-2');
    assert.equal(flow.state.selectedMythosColor, null);
    assert.equal(flow.selectMythosColor('green', oldSession), false);
});

test('Action tap stops Muted Action while hold switches ordinary Action and toggles Muted Action', () => {
    const { flow, audio, advance, music } = setup();
    flow.selectAction();
    flow.selectAction('action-muted');
    assert.equal(audio.state.playingFolder, 'action-muted');
    advance();
    flow.selectAction('action-muted');
    assert.equal(audio.state.playingFolder, null);
    advance();
    flow.selectAction('action-muted');
    flow.selectAction();
    assert.equal(music().at(-1)[0], 'stop');
    assert.equal(flow.state.lastWhiteProfile, 'action');
});

test('Combat and Epic Combat have distinct profiles while retaining tap/hold audio behavior', () => {
    const { flow, audio, lights, advance } = setup();
    flow.selectAction();
    flow.selectCombat();
    assert.deepEqual(lights().at(-1), { kind: 'white', profile: 'combat' });
    flow.enterMythos();
    flow.selectCombat('combat-epic');
    assert.deepEqual(lights().at(-1), { kind: 'white', profile: 'combat-epic' });
    const count = lights().length;
    flow.selectCombat();
    assert.equal(audio.state.playingFolder, null);
    advance();
    flow.selectCombat();
    flow.selectCombat('combat-epic');
    assert.equal(audio.state.playingFolder, 'combat-epic');
    assert.equal(lights().length, count + 3);
    assert.equal(flow.state.lastWhiteProfile, 'combat-epic');
    assert.deepEqual(lights().slice(count).map(target => target.profile), ['combat', 'combat', 'combat-epic']);
});

test('Encounters header only navigates and preserves the current Action music and light', () => {
    const { flow, events, audio } = setup();
    flow.selectAction();
    events.length = 0;
    flow.enterEncounters();
    flow.observeNavigation('/encounters');
    assert.deepEqual(events, [['navigate', '/encounters']]);
    assert.equal(flow.state.selectedContext, 'action');
    assert.equal(flow.state.lastWhiteProfile, 'action');
    assert.equal(audio.state.playingFolder, 'action');
});

test('leaving and returning to Mythos preserves its session, selected scene and continuing music', () => {
    const { flow, lights, music, audio } = setup({ initialUrl: '/mythos' });
    flow.observeNavigation('/mythos?refresh=1');
    assert.equal(lights().length, 0);
    flow.enterMythos();
    const session = flow.state.mythosSessionId;
    flow.selectMythosColor('blue', session);
    const count = lights().length;
    flow.enterEncounters();
    for (const url of ['/encounters', '/encounters/general/city', '/other', '/', '/mythos', '/mythos?props=updated']) {
        flow.observeNavigation(url);
        assert.equal(lights().length, count, url);
        assert.equal(flow.state.mythosSessionId, session, url);
        assert.equal(flow.state.selectedMythosColor, 'blue', url);
        assert.equal(flow.state.selectedContext, 'mythos', url);
    }
    assert.equal(audio.state.playingFolder, 'mythos');
    assert.equal(music().length, 1);
});

test('a competing route completion cannot cancel a newly selected Mythos phase', () => {
    const { flow, lights } = setup({ initialUrl: '/' });
    flow.enterMythos();
    const session = flow.state.mythosSessionId;
    flow.observeNavigation('/?props=refreshed');
    assert.equal(flow.state.selectedContext, 'mythos');
    flow.observeNavigation('/other');
    assert.equal(flow.state.mythosSessionId, session);
    assert.equal(lights().length, 1);
    assert.equal(flow.selectMythosColor('blue', session), true);
    assert.deepEqual(lights().at(-1), { kind: 'mythos', mythosSessionId: session, color: 'blue' });
});

test('same color can be sent explicitly after linking or failure, but accepted target stays idempotent', () => {
    const { flow, lighting, lights, advance } = setup();
    lighting.hasTarget = () => false;
    flow.enterMythos();
    const session = flow.state.mythosSessionId;
    flow.selectMythosColor('green', session);
    advance();
    assert.equal(flow.selectMythosColor('green', session), true);
    lighting.hasTarget = () => true;
    advance();
    assert.equal(flow.selectMythosColor('green', session), false);
    assert.equal(lights().length, 3);
});

test('Ancient One uses its preset in both branches while generic contacts retain Encounters', () => {
    const { flow, lights } = setup();
    const play = (folderSlug, context) => flow.playUserChoice({ folderSlug, label: 'test', mode: 'random_pos_fade', crossfade: true }, context);
    play('contacts/city');
    assert.deepEqual(lights().at(-1), { kind: 'white', profile: 'encounters' });
    play('ancient/test', 'encounters');
    assert.equal(flow.state.selectedContext, 'encounters');
    const count = lights().length;
    play('ancient/test', 'other');
    assert.equal(lights().length, count + 1);
    assert.deepEqual(lights().at(-1), { kind: 'white', profile: 'ancient-one' });
    assert.equal(flow.state.selectedContext, 'other');
    assert.equal(resolveGameContext('ancient/test', null, '/encounters'), 'encounters');
    assert.equal(resolveGameContext('ancient/test', null, '/other'), 'other');
    assert.equal(resolveGameContext('ancient/test'), 'other');
    assert.equal(resolveGameContext('contacts/test'), 'encounters');
});

test('ordinary music choices resolve individual presets from their folder', () => {
    for (const [folderSlug, profile] of [
        ['combat', 'combat'], ['combat-epic', 'combat-epic'], ['ancient/test', 'ancient-one'],
        ['special/victory', 'victory'], ['special/characters/test', 'intro-investigators'],
        ['maps/test', 'encounters'], ['action', 'action'], ['action-muted', 'action-muted'],
    ]) {
        const { flow, lights, events } = setup();
        flow.playUserChoice({ folderSlug, crossfade: true });
        assert.deepEqual(events.map(([type]) => type), ['play', 'light'], folderSlug);
        assert.deepEqual(lights(), [{ kind: 'white', profile }], folderSlug);
    }
});

test('saved branch metadata cannot override the actual Action, Combat or Mythos music folder', () => {
    for (const [folderSlug, profile] of [['action', 'action'], ['action-muted', 'action-muted'],
        ['combat', 'combat'], ['combat-epic', 'combat-epic']]) {
        const { flow, lights } = setup();
        flow.playUserChoice({ folderSlug }, 'other');
        assert.equal(flow.state.selectedContext, folderSlug);
        assert.deepEqual(lights(), [{ kind: 'white', profile }]);
    }
    const { flow, lights, audio } = setup();
    flow.playUserChoice({ folderSlug: 'mythos' }, 'other');
    assert.equal(audio.state.playingFolder, 'mythos');
    assert.equal(lights()[0].kind, 'mythos');
    assert.equal(flow.state.selectedContext, 'mythos');
    for (const staleContext of ['action', 'mythos']) {
        flow.playUserChoice({ folderSlug: `special/${staleContext}` }, staleContext);
        assert.equal(flow.state.selectedContext, 'other');
        assert.deepEqual(lights().at(-1), { kind: 'white', profile: 'encounters' });
    }
});

test('Other, nested navigation, Home and history preserve every active music context', () => {
    for (const choose of [flow => flow.selectAction(), flow => flow.selectAction('action-muted'),
        flow => flow.selectCombat(), flow => flow.selectCombat('combat-epic'),
        flow => flow.playUserChoice({ folderSlug: 'contacts/city' }),
        flow => flow.playUserChoice({ folderSlug: 'special/victory' })]) {
        const { flow, events, audio } = setup();
        choose(flow);
        const before = { ...flow.state };
        const playing = audio.state.playingFolder;
        events.length = 0;
        for (const url of ['/other', '/other/quest', '/encounters', '/encounters/general/city', '/', '/other', '/mythos']) {
            flow.observeNavigation(url);
            assert.deepEqual(events, [], url);
            assert.deepEqual(flow.state, before, url);
            assert.equal(audio.state.playingFolder, playing, url);
        }
    }
});

test('navigation alone never selects a context or sends commands on a fresh page', () => {
    const { flow, events } = setup({ initialUrl: '/mythos' });
    flow.enterEncounters();
    assert.deepEqual(events, [['navigate', '/encounters']]);
    events.length = 0;
    for (const url of ['/encounters', '/other', '/', '/mythos', '/mythos?refresh=1']) {
        flow.observeNavigation(url);
    }
    assert.deepEqual(events, []);
    assert.equal(flow.state.selectedContext, null);
    assert.equal(flow.state.mythosSessionId, null);
});

test('opening the Mythos page through navigation preserves its selected color and never creates a session', () => {
    const { flow, lights } = setup();
    flow.observeNavigation('/mythos');
    assert.equal(flow.state.mythosSessionId, null);
    assert.deepEqual(lights(), []);
    flow.enterMythos();
    flow.selectMythosColor('blue', flow.state.mythosSessionId);
    flow.observeNavigation('/other');
    flow.observeNavigation('/mythos');
    assert.equal(flow.state.selectedMythosColor, 'blue');
    assert.equal(lights().length, 2);
});

test('the next terminal music choice after navigation ends Mythos and rejects its old color events', () => {
    const { flow, lights, audio } = setup();
    flow.enterMythos();
    const session = flow.state.mythosSessionId;
    flow.selectMythosColor('yellow', session);
    flow.enterEncounters();
    flow.observeNavigation('/encounters/general/city');
    assert.equal(flow.state.mythosSessionId, session);
    assert.equal(lights().length, 2);
    flow.playUserChoice({ folderSlug: 'contacts/city', crossfade: true });
    assert.equal(flow.state.mythosSessionId, null);
    assert.equal(flow.state.selectedContext, 'encounters');
    assert.equal(audio.state.playingFolder, 'contacts/city');
    assert.equal(flow.selectMythosColor('blue', session), false);
    assert.deepEqual(lights().at(-1), { kind: 'white', profile: 'encounters' });
});

test('generic music duplicate and toggle retain audio options', () => {
    const { flow, music, advance } = setup();
    const options = { folderSlug: 'special/victory', mode: 'from_start_no_fade', label: 'Victory', crossfade: true };
    flow.playUserChoice(options, 'other');
    assert.equal(flow.playUserChoice(options, 'other'), false);
    assert.deepEqual(music(), [['play', options]]);
    advance();
    flow.playUserChoice(options, 'other');
    assert.equal(music().at(-1)[0], 'stop');
});

test('pause, resume, and clear-blobs stop keep the selected Mythos scene', () => {
    const { flow, lights, advance } = setup();
    flow.enterMythos();
    flow.selectMythosColor('green', flow.state.mythosSessionId);
    flow.togglePause();
    assert.equal(flow.togglePause(), false);
    advance();
    flow.togglePause();
    flow.stopUserAudio();
    assert.equal(lights().length, 2);
    assert.equal(flow.state.selectedMythosColor, 'green');
});

test('lighting failures do not reject audio, navigation, or color selection', async () => {
    const { flow, lighting, events } = setup();
    lighting.requestTarget = () => { throw new Error('offline'); };
    assert.doesNotThrow(() => flow.enterMythos());
    assert.deepEqual(events.map(([type]) => type), ['play', 'navigate']);
    lighting.requestTarget = () => Promise.reject(new Error('offline'));
    flow.selectMythosColor('blue', flow.state.mythosSessionId);
    await new Promise(setImmediate);
    assert.equal(flow.state.selectedMythosColor, 'blue');
});

test('audio failures are reported separately and never roll back chosen lighting', async () => {
    const { flow, audio, lights } = setup();
    audio.play = () => Promise.resolve(); // Engine can swallow a missing-folder response.
    flow.enterMythos();
    flow.selectMythosColor('green', flow.state.mythosSessionId);
    await new Promise(setImmediate);
    assert.match(flow.state.audioError, /Unable to start/);
    assert.equal(flow.state.selectedContext, 'mythos');
    assert.equal(lights().at(-1).color, 'green');
});

test('a stale failed audio request cannot overwrite newer successful audio status', async () => {
    const { flow, audio } = setup();
    let rejectOld;
    audio.play = () => new Promise((resolve, reject) => { rejectOld = reject; });
    flow.enterMythos();
    audio.play = (options) => { audio.state.playingFolder = options.folderSlug; };
    flow.selectAction();
    rejectOld(new Error('old failure'));
    await new Promise(setImmediate);
    assert.equal(flow.state.audioError, null);
});

test('logout immediately invalidates Mythos and releases lighting while fading music', async () => {
    const { flow, events, lights } = setup({ initialUrl: '/mythos' });
    flow.enterMythos();
    const session = flow.state.mythosSessionId;
    events.length = 0;
    const { fadePromise, releasePromise } = flow.logout();
    assert.deepEqual(events, [['fade'], ['release']]);
    assert.equal(flow.selectMythosColor('blue', session), false);
    flow.observeNavigation('/login');
    assert.equal(lights().length, 0);
    await Promise.all([fadePromise, releasePromise]);
});

test('900ms header hold fires only Muted Action and suppresses the release tap', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const { flow, music } = setup();
    const bindings = useLongPress({
        onTap: () => flow.selectAction(), onLongPress: () => flow.selectAction('action-muted'), threshold: 900,
    });
    bindings.onPointerdown({ clientX: 0, clientY: 0 });
    t.mock.timers.tick(899);
    assert.equal(music().length, 0);
    t.mock.timers.tick(1);
    bindings.onPointerup({});
    assert.equal(music().length, 1);
    assert.equal(music()[0][1].folderSlug, 'action-muted');
});

test('1000ms ordinary-button hold saves a blob without audio or lighting', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const { flow, events } = setup();
    let blobs = 0;
    const bindings = useLongPress({
        onTap: () => flow.playUserChoice({ folderSlug: 'contacts/city', crossfade: true }, 'encounters'),
        onLongPress: () => { blobs += 1; }, threshold: 1000,
    });
    bindings.onPointerdown({ clientX: 0, clientY: 0 });
    t.mock.timers.tick(1000);
    bindings.onPointerup({});
    assert.equal(blobs, 1);
    assert.deepEqual(events, []);
});

test('session identifiers satisfy the server UUID contract', () => {
    assert.match(createSessionId(), /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i);
});

function setupColorExit(t, overrides = {}, color = 'blue') {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const result = setup({ getMusicDelayMs: () => 2500, ...overrides });
    result.flow.enterMythos();
    result.flow.selectMythosColor(color, result.flow.state.mythosSessionId);
    result.events.length = 0;
    return { ...result, tick(ms) { result.advance(ms); t.mock.timers.tick(ms); } };
}

for (const color of ['green', 'yellow', 'blue']) {
    test(`${color} keeps Mythos music during color fade-out, then starts Action`, (t) => {
        const { flow, audio, lights, music, tick } = setupColorExit(t, {}, color);
        const session = flow.state.mythosSessionId;
        flow.selectAction();
        assert.deepEqual(lights(), [{ kind: 'white', profile: 'action' }]);
        assert.equal(flow.state.mythosSessionId, null);
        assert.equal(flow.selectMythosColor('green', session), false);
        assert.equal(flow.state.pendingMusicFolder, 'action');
        assert.deepEqual(music(), []);
        tick(2499);
        assert.equal(audio.state.playingFolder, 'mythos');
        tick(1);
        assert.equal(audio.state.playingFolder, 'action');
        assert.equal(flow.state.pendingMusicFolder, null);
        assert.equal(music().length, 1);
        tick(10000);
        assert.equal(music().length, 1);
    });
}

for (const [folderSlug, choose, profile] of [
    ['action-muted', flow => flow.selectAction('action-muted'), 'action-muted'],
    ['combat', flow => flow.selectCombat(), 'combat'],
    ['combat-epic', flow => flow.selectCombat('combat-epic'), 'combat-epic'],
    ['contacts/city', flow => flow.playUserChoice({ folderSlug: 'contacts/city', crossfade: true }, 'encounters'), 'encounters'],
    ['special/victory', flow => flow.playUserChoice({ folderSlug: 'special/victory', mode: 'from_start_no_fade', crossfade: true }), 'victory'],
]) {
    test(`color exit delays ${folderSlug} while sending its white profile immediately`, (t) => {
        const { flow, audio, lights, music, tick } = setupColorExit(t);
        choose(flow);
        assert.deepEqual(lights(), [{ kind: 'white', profile }]);
        assert.deepEqual(music(), []);
        tick(2500);
        assert.equal(audio.state.playingFolder, folderSlug);
        assert.equal(music()[0][1].crossfade, true);
        if (folderSlug === 'special/victory') assert.equal(music()[0][1].mode, 'from_start_no_fade');
    });
}

test('Mythos without a color and ordinary-to-ordinary choices have no music delay', () => {
    const { flow, audio } = setup({ getMusicDelayMs: () => 2500 });
    flow.enterMythos();
    flow.selectAction();
    assert.equal(audio.state.playingFolder, 'action');
    flow.selectCombat();
    assert.equal(audio.state.playingFolder, 'combat');
});

test('users without lighting access never delay music even with stale color selection', (t) => {
    let allowed = true;
    const { flow, audio, lights, tick } = setupColorExit(t, { canControlLighting: () => allowed });
    allowed = false;
    flow.selectAction();
    assert.equal(audio.state.playingFolder, 'action');
    assert.deepEqual(lights(), []);
    tick(2500);
    assert.equal(audio.state.playingFolder, 'action');
});

test('paused or stopped Mythos does not delay the next music choice', () => {
    for (const command of ['togglePause', 'stopUserAudio']) {
        const { flow, audio } = setup({ getMusicDelayMs: () => 2500 });
        flow.enterMythos();
        flow.selectMythosColor('blue', flow.state.mythosSessionId);
        flow[command]();
        flow.selectAction();
        assert.equal(audio.state.playingFolder, 'action', command);
    }
});

test('rapid choices with the same white profile replace queued music and keep the fade deadline', (t) => {
    const { flow, audio, music, tick } = setupColorExit(t);
    flow.playUserChoice({ folderSlug: 'contacts/sea' });
    assert.equal(flow.playUserChoice({ folderSlug: 'contacts/sea' }), false);
    tick(1000);
    flow.playUserChoice({ folderSlug: 'contacts/wilderness' });
    tick(1000);
    flow.playUserChoice({ folderSlug: 'contacts/city', crossfade: true });
    tick(499);
    assert.equal(audio.state.playingFolder, 'mythos');
    tick(1);
    assert.equal(audio.state.playingFolder, 'contacts/city');
    assert.equal(music().length, 1);
    tick(10000);
    assert.equal(music().length, 1);
});

test('changing the queued white profile restarts the delay with the replanned color fade', (t) => {
    const { flow, audio, lights, music, tick } = setupColorExit(t);
    flow.selectAction();
    tick(1000);
    flow.selectCombat();
    assert.deepEqual(lights().map(target => target.profile), ['action', 'combat']);
    tick(2499);
    assert.equal(audio.state.playingFolder, 'mythos');
    tick(1);
    assert.equal(audio.state.playingFolder, 'combat');
    assert.equal(music().length, 1);
});

test('navigation during a color exit preserves the queued music', (t) => {
    const { flow, audio, tick } = setupColorExit(t);
    flow.selectAction();
    flow.enterEncounters();
    for (const url of ['/encounters', '/other', '/', '/mythos']) flow.observeNavigation(url);
    tick(2500);
    assert.equal(audio.state.playingFolder, 'action');
});

test('returning to Mythos cancels queued music without restarting the playing Mythos track', (t) => {
    const { flow, audio, lights, music, tick } = setupColorExit(t);
    flow.selectAction();
    tick(500);
    flow.enterMythos();
    assert.equal(flow.state.selectedMythosColor, null);
    assert.equal(flow.state.pendingMusicFolder, null);
    assert.equal(lights().at(-1).kind, 'mythos');
    tick(2500);
    assert.equal(audio.state.playingFolder, 'mythos');
    assert.deepEqual(music(), []);
    flow.selectAction(); // New Mythos session has no selected color.
    assert.equal(audio.state.playingFolder, 'action');
});

for (const command of ['togglePause', 'stopUserAudio', 'logout', 'cancelPendingMusic']) {
    test(`${command} cancels deferred music with no late playback`, (t) => {
        const { flow, music, tick } = setupColorExit(t);
        flow.selectAction();
        tick(500);
        flow[command]();
        assert.equal(flow.state.pendingMusicFolder, null);
        const count = music().length;
        tick(10000);
        assert.equal(music().length, count);
    });
}

test('permission revocation before the deadline blocks deferred playback', (t) => {
    let allowed = true;
    const { flow, music, tick } = setupColorExit(t, { canControlLighting: () => allowed });
    flow.selectAction();
    allowed = false;
    tick(2500);
    assert.deepEqual(music(), []);
    assert.equal(flow.state.pendingMusicFolder, null);
});

test('delay reads the current config; unavailable lighting cannot stall audio forever', (t) => {
    let duration = 2500;
    const { flow, audio, lighting, tick } = setupColorExit(t, { getMusicDelayMs: () => duration });
    duration = 4500; // Server-calculated 2500 ms color fade multiplied by 1.8.
    lighting.requestTarget = () => new Promise(() => {});
    flow.selectAction();
    tick(4499);
    assert.equal(audio.state.playingFolder, 'mythos');
    tick(1);
    assert.equal(audio.state.playingFolder, 'action');
});

test('zero or invalid music delay timings use immediate playback', () => {
    for (const duration of [0, -10, NaN, undefined, Infinity]) {
        const { flow, audio } = setup({ getMusicDelayMs: () => duration });
        flow.enterMythos();
        flow.selectMythosColor('blue', flow.state.mythosSessionId);
        flow.selectAction();
        assert.equal(audio.state.playingFolder, 'action');
    }
});

test('restriction and all nine gate locations send independent colour profiles', () => {
    const folders = [
        ['contacts/obstruction/detained', 'detained'], ['contacts/obstruction/lost', 'lost'],
        ...['carcosa', 'great-race', 'yuggoth', 'celaeno', 'leng', 'kadath', 'underworld', 'abyss']
            .map(profile => [`contacts/other-world/${profile}`, profile]),
        ['contacts/other-world/dreamlands', 'dreamlands-gate'],
    ];
    const { flow, lights } = setup();
    for (const [folderSlug, profile] of folders) {
        flow.playUserChoice({ folderSlug }, 'encounters');
        assert.deepEqual(lights().at(-1), { kind: 'colour', profile }, folderSlug);
    }
});

test('all sixteen time locations resolve individually, including the two white future presets', () => {
    const { flow, lights } = setup();
    for (const period of ['past', 'future']) {
        for (let number = 1; number <= 8; number++) {
            flow.playUserChoice({ folderSlug: `contacts/other-world/${period}/${number}` });
            const kind = period === 'future' && [1, 6].includes(number) ? 'white' : 'colour';
            assert.deepEqual(lights().at(-1), { kind, profile: `${period}-${number}` });
        }
    }
});

test('every side-board location, including four Dream Quest locations, shares its board preset', () => {
    const boards = [
        ['antarctica', 'antarctica', ['frozen-waste', 'lake-camp', 'miskatonic-outpost', 'city-of-elder-things', 'plateau-of-leng', 'snowy-mountains']],
        ['egypt', 'egypt', ['alexandria', 'bent-pyramid', 'cairo', 'sahara-desert', 'tel-el-amarna', 'nile-river']],
        ['dreamlands', 'dreamlands-board', ['unknown-kadath', 'enchanted-wood', 'celephais', 'ulthar', 'dylath-leen', 'underworld', 'moon']],
    ];
    const { flow, lights } = setup();
    for (const [board, profile, locations] of boards) {
        for (const location of locations) {
            flow.playUserChoice({ folderSlug: `contacts/${board}/${location}` });
            assert.deepEqual(lights().at(-1), { kind: 'white', profile }, location);
        }
    }
});

test('Other terminal buttons and disaster branches resolve their configured white presets', () => {
    const { flow, lights } = setup();
    for (const [folderSlug, profile] of [
        ['special/defeat', 'defeat'], ['special/victory', 'victory'], ['special/awakening', 'awakening'],
        ['special/death-sacrifice', 'sacrifice'], ['special/honey-pie', 'intro-investigators'],
        ['special/characters/akachi-onyele', 'intro-investigators'], ['ancient/azathoth', 'ancient-one'],
        ['special/disaster/city/rome', 'disaster-city'], ['special/disaster/weather/polar-vortex', 'disaster-weather'],
        ['special/disaster/location/meteor-showers', 'disaster-location'],
    ]) {
        flow.playUserChoice({ folderSlug }, 'other');
        assert.deepEqual(lights().at(-1), { kind: 'white', profile }, folderSlug);
    }
});

test('prefix boundaries and unconfigured encounters preserve the generic preset', () => {
    const { flow, lights } = setup();
    for (const folderSlug of ['contacts/antarctica-extra/frozen-waste', 'contacts/general/sea/antarctica',
        'contacts/general/wilderness/pyramids', 'contacts/defeated/sanity', 'contacts/devastation',
        'contacts/expedition/ruins', 'contacts/big-city/rome', 'constructor']) {
        flow.playUserChoice({ folderSlug });
        assert.deepEqual(lights().at(-1), { kind: 'white', profile: 'encounters' }, folderSlug);
    }
});

for (const folderSlug of ['special/death-sanity', 'special/death-health', 'special/devoured']) {
    test(`${folderSlug} plays immediately without changing Mythos light or accepting stale color events`, (t) => {
        const { flow, audio, lights, tick } = setupColorExit(t);
        const oldSession = flow.state.mythosSessionId;
        flow.playUserChoice({ folderSlug });
        assert.equal(audio.state.playingFolder, folderSlug);
        assert.deepEqual(lights(), []);
        assert.equal(flow.selectMythosColor('yellow', oldSession), false);
        flow.selectAction();
        assert.equal(audio.state.playingFolder, folderSlug);
        tick(2500);
        assert.equal(audio.state.playingFolder, 'action');
    });

    test(`${folderSlug} cancels queued music and preserves the already requested lamp transition`, (t) => {
        const { flow, audio, lights, music, tick } = setupColorExit(t);
        flow.selectAction();
        tick(500);
        flow.playUserChoice({ folderSlug });
        assert.equal(audio.state.playingFolder, folderSlug);
        assert.equal(flow.state.pendingMusicFolder, null);
        assert.deepEqual(lights(), [{ kind: 'white', profile: 'action' }]);
        tick(10000);
        assert.equal(audio.state.playingFolder, folderSlug);
        assert.equal(music().length, 1);
    });
}

test('death before the first lamp selection never acquires lighting control', () => {
    const { flow, lights, audio } = setup();
    flow.playUserChoice({ folderSlug: 'special/devoured' });
    assert.equal(audio.state.playingFolder, 'special/devoured');
    assert.deepEqual(lights(), []);
});

test('death leaves the remaining colour fade deadline intact for a subsequent matching target', (t) => {
    const { flow, audio, tick } = setupColorExit(t);
    flow.selectAction();
    tick(500);
    flow.playUserChoice({ folderSlug: 'special/devoured' });
    tick(500);
    flow.selectAction();
    tick(1499);
    assert.equal(audio.state.playingFolder, 'special/devoured');
    tick(1);
    assert.equal(audio.state.playingFolder, 'action');
});

for (const folderSlug of ['contacts/other-world/future/1', 'contacts/other-world/future/6',
    'contacts/obstruction/lost', 'combat']) {
    test(`static colour exit delays ${folderSlug} with the encounter policy`, (t) => {
        t.mock.timers.enable({ apis: ['setTimeout'] });
        const { flow, audio, lights, music, events, advance } = setup({ getMusicDelayMs: () => 4500, getEncounterMusicDelayMs: () => 1500 });
        flow.playUserChoice({ folderSlug: 'contacts/obstruction/detained' });
        events.length = 0;
        flow.playUserChoice({ folderSlug });
        assert.equal(lights().length, 1);
        assert.equal(music().length, 0);
        assert.equal(audio.state.playingFolder, 'contacts/obstruction/detained');
        advance(1500);
        t.mock.timers.tick(1500);
        assert.equal(audio.state.playingFolder, folderSlug);
    });
}

test('static colour music toggles immediately while retaining its colour target', () => {
    const { flow, audio, advance, lights } = setup({ getMusicDelayMs: () => 4500, getEncounterMusicDelayMs: () => 1500 });
    const options = { folderSlug: 'contacts/obstruction/detained' };
    flow.playUserChoice(options);
    advance();
    flow.playUserChoice(options);
    assert.equal(audio.state.playingFolder, null);
    assert.equal(flow.state.pendingMusicFolder, null);
    assert.deepEqual(lights().at(-1), { kind: 'colour', profile: 'detained' });
});

test('death preserves static colour independently of its music context', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const { flow, audio, lights, events, advance } = setup({ getMusicDelayMs: () => 4500, getEncounterMusicDelayMs: () => 1500 });
    flow.playUserChoice({ folderSlug: 'contacts/obstruction/detained' });
    events.length = 0;
    flow.playUserChoice({ folderSlug: 'special/death-health' });
    assert.deepEqual(lights(), []);
    flow.selectCombat();
    assert.equal(audio.state.playingFolder, 'special/death-health');
    advance(1500);
    t.mock.timers.tick(1500);
    assert.equal(audio.state.playingFolder, 'combat');
});

test('entering Mythos from static colour delays its music but establishes the new session immediately', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const { flow, audio, lights, events, advance } = setup({ getMusicDelayMs: () => 4500, getEncounterMusicDelayMs: () => 1500 });
    flow.playUserChoice({ folderSlug: 'contacts/obstruction/detained' });
    events.length = 0;
    flow.enterMythos();
    assert.equal(flow.state.mythosSessionId, 'session-1');
    assert.equal(audio.state.playingFolder, 'contacts/obstruction/detained');
    assert.deepEqual(lights(), [{ kind: 'mythos', mythosSessionId: 'session-1', color: null }]);
    advance(1500);
    t.mock.timers.tick(1500);
    assert.equal(audio.state.playingFolder, 'mythos');
});

function setupEncounterTiming(t, overrides = {}) {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const result = setup({ getMusicDelayMs: () => 4500, getEncounterMusicDelayMs: () => 1500, ...overrides });
    return { ...result, tick(ms) { result.advance(ms); t.mock.timers.tick(ms); } };
}

const encounterFolders = [
    'contacts/obstruction/detained', 'contacts/obstruction/lost',
    ...['carcosa', 'great-race', 'yuggoth', 'celaeno', 'leng', 'dreamlands', 'kadath', 'underworld', 'abyss']
        .map(location => `contacts/other-world/${location}`),
    ...['past', 'future'].flatMap(period => Array.from({ length: 8 }, (_, i) => `contacts/other-world/${period}/${i + 1}`)),
];

test('all 27 restriction and other-world presets delay music on both entry and exit by 1500ms', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    for (const folderSlug of encounterFolders) {
        const { flow, audio, events, advance } = setup({ getMusicDelayMs: () => 4500, getEncounterMusicDelayMs: () => 1500 });
        const tick = ms => { advance(ms); t.mock.timers.tick(ms); };
        flow.selectAction();
        events.length = 0;
        flow.playUserChoice({ folderSlug });
        assert.deepEqual(events.map(([type]) => type), ['light'], folderSlug);
        tick(1499);
        assert.equal(audio.state.playingFolder, 'action', folderSlug);
        tick(1);
        assert.equal(audio.state.playingFolder, folderSlug);
        events.length = 0;
        flow.selectAction();
        assert.deepEqual(events.map(([type]) => type), ['light'], folderSlug);
        tick(1499);
        assert.equal(audio.state.playingFolder, folderSlug);
        tick(1);
        assert.equal(audio.state.playingFolder, 'action', folderSlug);
    }
});

test('white Future presets use the encounter delay between one another', (t) => {
    const { flow, audio, lights, tick } = setupEncounterTiming(t);
    flow.playUserChoice({ folderSlug: 'contacts/other-world/future/1' });
    flow.playUserChoice({ folderSlug: 'contacts/other-world/future/6' });
    assert.deepEqual(lights().at(-1), { kind: 'white', profile: 'future-6' });
    tick(1499);
    assert.equal(audio.state.playingFolder, 'contacts/other-world/future/1');
    tick(1);
    assert.equal(audio.state.playingFolder, 'contacts/other-world/future/6');
});

test('first encounter playback and paused or stopped audio begin immediately', () => {
    for (const prepare of [() => {}, flow => { flow.selectAction(); flow.togglePause(); },
        flow => { flow.selectAction(); flow.stopUserAudio(); }]) {
        for (const folderSlug of ['contacts/obstruction/detained', 'contacts/other-world/future/1']) {
            const { flow, audio } = setup({ getMusicDelayMs: () => 4500, getEncounterMusicDelayMs: () => 1500 });
            prepare(flow);
            flow.playUserChoice({ folderSlug });
            assert.equal(audio.state.playingFolder, folderSlug);
            assert.equal(flow.state.pendingMusicFolder, null);
        }
    }
});

test('phase-only Mythos colour exit retains 4500ms and ordinary white phases remain immediate', (t) => {
    const { flow, audio, tick } = setupEncounterTiming(t);
    flow.selectAction();
    flow.selectCombat();
    assert.equal(audio.state.playingFolder, 'combat');
    flow.enterMythos();
    assert.equal(audio.state.playingFolder, 'mythos');
    flow.selectMythosColor('blue', flow.state.mythosSessionId);
    flow.selectAction();
    tick(4499);
    assert.equal(audio.state.playingFolder, 'mythos');
    tick(1);
    assert.equal(audio.state.playingFolder, 'action');
});

test('repeating a pending encounter target preserves its original music deadline', (t) => {
    const { flow, audio, music, tick } = setupEncounterTiming(t);
    flow.selectAction();
    flow.playUserChoice({ folderSlug: 'contacts/obstruction/detained' });
    tick(500);
    flow.playUserChoice({ folderSlug: 'contacts/obstruction/detained' });
    tick(999);
    assert.equal(audio.state.playingFolder, 'action');
    tick(1);
    assert.equal(audio.state.playingFolder, 'contacts/obstruction/detained');
    assert.equal(music().length, 2);
});

test('a changed destination during encounter exit retains the encounter source policy', (t) => {
    const { flow, audio, tick } = setupEncounterTiming(t);
    flow.playUserChoice({ folderSlug: 'contacts/other-world/future/1' });
    flow.selectAction();
    tick(500);
    flow.selectCombat();
    tick(1499);
    assert.equal(audio.state.playingFolder, 'contacts/other-world/future/1');
    tick(1);
    assert.equal(audio.state.playingFolder, 'combat');
});

test('an encounter destination replaces a pending 4500ms phase delay with its 1500ms policy', (t) => {
    const { flow, audio, tick } = setupEncounterTiming(t);
    flow.enterMythos();
    flow.selectMythosColor('green', flow.state.mythosSessionId);
    flow.selectAction();
    tick(500);
    flow.playUserChoice({ folderSlug: 'contacts/other-world/future/6' });
    tick(1499);
    assert.equal(audio.state.playingFolder, 'mythos');
    tick(1);
    assert.equal(audio.state.playingFolder, 'contacts/other-world/future/6');
    tick(4500);
    assert.equal(audio.state.playingFolder, 'contacts/other-world/future/6');
});

test('completed encounter handoff does not leak its delay into later phase-only transitions', (t) => {
    const { flow, audio, tick } = setupEncounterTiming(t);
    flow.playUserChoice({ folderSlug: 'contacts/obstruction/detained' });
    flow.enterMythos();
    tick(1500);
    flow.selectMythosColor('yellow', flow.state.mythosSessionId);
    flow.selectAction();
    tick(4499);
    assert.equal(audio.state.playingFolder, 'mythos');
    tick(1);
    assert.equal(audio.state.playingFolder, 'action');
});

test('death keeps the encounter exit deadline and never sends a light replacement', (t) => {
    const { flow, audio, lights, tick } = setupEncounterTiming(t);
    flow.playUserChoice({ folderSlug: 'contacts/other-world/future/1' });
    flow.selectAction();
    tick(500);
    flow.playUserChoice({ folderSlug: 'special/devoured' });
    assert.equal(lights().length, 2);
    assert.equal(audio.state.playingFolder, 'special/devoured');
    tick(500);
    flow.selectAction();
    tick(499);
    assert.equal(audio.state.playingFolder, 'special/devoured');
    tick(1);
    assert.equal(audio.state.playingFolder, 'action');
});

test('returning to playing Mythos retains an in-flight encounter policy for the next choice', (t) => {
    const { flow, audio, tick } = setupEncounterTiming(t);
    flow.enterMythos();
    flow.playUserChoice({ folderSlug: 'contacts/other-world/future/1' });
    tick(500);
    flow.enterMythos();
    assert.equal(audio.state.playingFolder, 'mythos');
    tick(500);
    flow.selectCombat();
    tick(1499);
    assert.equal(audio.state.playingFolder, 'mythos');
    tick(1);
    assert.equal(audio.state.playingFolder, 'combat');
});

test('encounter delay reads current settings and remains optional without lighting access', (t) => {
    let delay = 1500;
    let allowed = true;
    const { flow, audio, tick } = setupEncounterTiming(t, {
        getEncounterMusicDelayMs: () => delay, canControlLighting: () => allowed,
    });
    flow.selectAction();
    delay = 900;
    flow.playUserChoice({ folderSlug: 'contacts/other-world/future/1' });
    tick(899);
    assert.equal(audio.state.playingFolder, 'action');
    tick(1);
    assert.equal(audio.state.playingFolder, 'contacts/other-world/future/1');
    allowed = false;
    flow.selectCombat();
    assert.equal(audio.state.playingFolder, 'combat');
});

test('encounter source policy survives the music deadline until the lamp actually completes', (t) => {
    let settled = false;
    const { flow, lighting, audio, tick } = setupEncounterTiming(t);
    lighting.isTargetSettled = () => settled;
    flow.playUserChoice({ folderSlug: 'contacts/other-world/future/1' });
    flow.selectAction();
    tick(1800); // Music has changed; the lamp is still fading.
    assert.equal(audio.state.playingFolder, 'action');
    flow.selectCombat();
    tick(1499);
    assert.equal(audio.state.playingFolder, 'action');
    tick(1);
    assert.equal(audio.state.playingFolder, 'combat');
    settled = true;
    flow.enterMythos();
    assert.equal(audio.state.playingFolder, 'mythos');
    flow.selectMythosColor('blue', flow.state.mythosSessionId);
    flow.selectAction();
    tick(4499);
    assert.equal(audio.state.playingFolder, 'mythos');
    tick(1);
    assert.equal(audio.state.playingFolder, 'action');
});

test('death after the music deadline preserves the still-running encounter light policy', (t) => {
    const { flow, lighting, audio, lights, tick } = setupEncounterTiming(t);
    lighting.isTargetSettled = () => false;
    flow.playUserChoice({ folderSlug: 'contacts/obstruction/detained' });
    flow.selectAction();
    tick(1800);
    flow.playUserChoice({ folderSlug: 'special/death-sanity' });
    assert.equal(lights().length, 2);
    flow.enterMythos();
    tick(1499);
    assert.equal(audio.state.playingFolder, 'special/death-sanity');
    tick(1);
    assert.equal(audio.state.playingFolder, 'mythos');
});

test('a new Mythos colour does not revive the policy of an already completed encounter exit', (t) => {
    const { flow, lighting, audio, lights, tick } = setupEncounterTiming(t);
    let settledTarget = null;
    lighting.isTargetSettled = target => JSON.stringify(target) === JSON.stringify(settledTarget);
    flow.playUserChoice({ folderSlug: 'contacts/obstruction/detained' });
    flow.enterMythos();
    tick(3000);
    settledTarget = lights().at(-1);
    assert.equal(settledTarget.kind, 'mythos');
    assert.equal(settledTarget.color, null);
    flow.selectMythosColor('blue', flow.state.mythosSessionId);
    assert.equal(lighting.isTargetSettled(lights().at(-1)), false);
    flow.selectAction();
    tick(4499);
    assert.equal(audio.state.playingFolder, 'mythos');
    tick(1);
    assert.equal(audio.state.playingFolder, 'action');
});

test('a new Mythos colour retains the policy of an unfinished encounter exit', (t) => {
    const { flow, lighting, audio, lights, tick } = setupEncounterTiming(t);
    const settledTarget = { kind: 'colour', profile: 'detained' };
    lighting.isTargetSettled = target => JSON.stringify(target) === JSON.stringify(settledTarget);
    flow.playUserChoice({ folderSlug: 'contacts/obstruction/detained' });
    flow.enterMythos();
    tick(1800);
    assert.equal(lighting.isTargetSettled(lights().at(-1)), false);
    flow.selectMythosColor('blue', flow.state.mythosSessionId);
    flow.selectAction();
    tick(1499);
    assert.equal(audio.state.playingFolder, 'mythos');
    tick(1);
    assert.equal(audio.state.playingFolder, 'action');
});

test('paused encounter exit starts music immediately and retains its unfinished policy for the next destination', (t) => {
    const { flow, lighting, audio, tick } = setupEncounterTiming(t);
    let settledTarget = null;
    lighting.isTargetSettled = target => JSON.stringify(target) === JSON.stringify(settledTarget);
    flow.playUserChoice({ folderSlug: 'contacts/other-world/future/1' });
    settledTarget = { kind: 'white', profile: 'future-1' };
    flow.togglePause();
    flow.selectAction();
    assert.equal(audio.state.playingFolder, 'action');
    assert.equal(flow.state.pendingMusicFolder, null);
    audio.state.isPaused = false; // The real play operation clears its paused state.
    tick(500);
    flow.selectCombat();
    tick(1499);
    assert.equal(audio.state.playingFolder, 'action');
    tick(1);
    assert.equal(audio.state.playingFolder, 'combat');
});
