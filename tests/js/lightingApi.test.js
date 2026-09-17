import test from 'node:test';
import assert from 'node:assert/strict';
import { createLightingClient } from '../../resources/js/lighting/api.js';
import { MYTHOS_CARD_BUTTONS } from '../../resources/js/lighting/palette.js';
import { createGameFlowController } from '../../resources/js/gameFlow/createController.js';

const flush = () => new Promise(setImmediate);

function deferred() {
    let resolve, reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
}

function status(version, extra = {}) {
    return { statusVersion: version, revision: version, driver: 'mock', simulated: true,
        enabled: true, controlGeneration: 'owner-1', stage: 'queued', error: null,
        ...(extra.controlEpoch ? { controlEpochGeneration: extra.controlGeneration ?? 'owner-1' } : {}), ...extra };
}

function setup() {
    const requests = [];
    const http = {
        get: (url) => { const d = deferred(); requests.push({ method: 'get', url, ...d }); return d.promise; },
        post: (url, body) => { const d = deferred(); requests.push({ method: 'post', url, body, ...d }); return d.promise; },
    };
    let id = 0;
    const client = createLightingClient({ http, uuid: () => `intent-${++id}` });
    async function enable() {
        const promise = client.enable();
        requests.at(-1).resolve({ data: status(1, { controlEpoch: 'secret-epoch' }) });
        await promise;
    }
    return { client, requests, enable };
}

test('status reads and a fresh client never acquire control or replay a target', async () => {
    const { client, requests } = setup();
    const read = client.poll();
    requests[0].resolve({ data: status(10, { target: { kind: 'mythos', color: 'blue' } }) });
    await read;
    assert.equal(client.state.linked, false);
    assert.equal(await client.sendTarget({ kind: 'white', profile: 'action' }), false);
    assert.equal(requests.length, 1);
});

test('link only obtains a fresh epoch; no implicit replay', async () => {
    const { client, requests, enable } = setup();
    await enable();
    assert.equal(client.state.linked, true);
    assert.equal(requests.length, 1);
    assert.deepEqual(requests[0].body, { enabled: true });
    assert.equal('controlEpoch' in client.state, false);
});

test('a delayed Link response cannot claim ownership already taken by another tab', async () => {
    const { client, requests } = setup();
    const link = client.enable();
    const read = client.poll();
    requests[1].resolve({ data: status(5, { controlGeneration: 'owner-2' }) });
    await read;
    requests[0].resolve({ data: status(1, { controlEpoch: 'expired-epoch' }) });
    assert.equal(await link, false);
    assert.equal(client.state.linked, false);
    assert.equal(client.state.controlGeneration, 'owner-2');
    assert.equal(client.state.error, 'control_lost');
    assert.equal(await client.sendTarget({ kind: 'white', profile: 'action' }), false);
});

test('an immutable epoch receipt cannot claim a newer owner merged into its public status', async () => {
    const { client, requests } = setup();
    const link = client.enable();
    requests[0].resolve({ data: status(5, {
        controlEpoch: 'old-owner-epoch', controlEpochGeneration: 'owner-1', controlGeneration: 'owner-2',
    }) });
    assert.equal(await link, false);
    assert.equal(client.state.linked, false);
    assert.equal(client.state.controlGeneration, 'owner-2');
    assert.equal(client.state.error, 'control_lost');
    assert.equal(await client.sendTarget({ kind: 'white', profile: 'action' }), false);
});

test('a control response without its immutable epoch generation cannot enable writes', async () => {
    const { client, requests } = setup();
    const link = client.enable();
    requests[0].resolve({ data: status(1, { controlEpoch: 'epoch', controlEpochGeneration: undefined }) });
    assert.equal(await link, false);
    assert.equal(client.state.linked, false);
    assert.equal(await client.sendTarget({ kind: 'white', profile: 'action' }), false);
});

test('a delayed Link response still accepts its epoch when a newer poll has the same owner', async () => {
    const { client, requests } = setup();
    const link = client.enable();
    const read = client.poll();
    requests[1].resolve({ data: status(5, { stage: 'error', error: 'offline' }) });
    await read;
    requests[0].resolve({ data: status(1, { controlEpoch: 'current-epoch' }) });
    assert.equal(await link, true);
    assert.equal(client.state.linked, true);
    assert.equal(client.state.stage, 'error');
    assert.equal(client.state.error, 'offline');
});

test('logout invalidates pending Link immediately and releases its late epoch before returning', async () => {
    const { client, requests } = setup();
    const link = client.enable();
    const logout = client.releaseForLogout();
    assert.equal(client.state.linked, false);
    assert.equal(client.state.controlPending, true);
    requests[0].resolve({ data: status(1, { controlEpoch: 'late-epoch' }) });
    assert.equal(await link, false);
    assert.equal(client.state.linked, false);
    assert.deepEqual(requests[1].body, { enabled: false, controlEpoch: 'late-epoch' });
    requests[1].resolve({ data: status(2, { enabled: false, controlGeneration: null, stage: 'cancelled' }) });
    await logout;
    assert.equal(client.state.controlPending, false);
    assert.equal(client.state.linked, false);
    assert.equal(client.state.remoteEnabled, false);
});

test('late intent responses cannot restore a superseded stage or target', async () => {
    const { client, requests, enable } = setup();
    await enable();
    const first = client.sendTarget({ kind: 'mythos', mythosSessionId: 'a', color: 'green' });
    const second = client.sendTarget({ kind: 'white', profile: 'action' });
    requests[2].resolve({ data: status(4, { accepted: true, stage: 'white_active' }) });
    await second;
    requests[1].resolve({ data: status(2, { accepted: true, stage: 'scene_active' }) });
    await first;
    assert.equal(client.state.stage, 'white_active');
    assert.equal(client.state.revision, 4);
    assert.equal(requests[1].body.clientSeq, 1);
    assert.equal(requests[2].body.clientSeq, 2);
});

test('a status read overtaken by a write cannot regress same-revision progress', async () => {
    const { client, requests, enable } = setup();
    await enable();
    const read = client.poll();
    const write = client.sendTarget({ kind: 'white', profile: 'action' });
    requests[2].resolve({ data: status(7, { accepted: true, revision: 2, stage: 'white_active' }) });
    await write;
    requests[1].resolve({ data: status(6, { revision: 2, stage: 'fading_white_up' }) });
    await read;
    assert.equal(client.state.stage, 'white_active');
});

test('an older failed status read cannot replace newer successful intent status with offline', async () => {
    const { client, requests, enable } = setup();
    await enable();
    const read = client.poll();
    const write = client.sendTarget({ kind: 'white', profile: 'action' });
    requests[2].resolve({ data: status(2, { accepted: true }) });
    await write;
    requests[1].reject(new Error('old request failed'));
    await read;
    assert.equal(client.state.error, null);
});

test('an explicit same-target retry after a status failure is sent instead of silently discarded', async () => {
    const { client, requests, enable } = setup();
    await enable();
    const target = { kind: 'mythos', mythosSessionId: 'a', color: 'green' };
    const write = client.sendTarget(target);
    requests[1].resolve({ data: status(2, { accepted: true }) });
    await write;
    const read = client.poll();
    requests[2].reject(new Error('offline'));
    await read;
    assert.equal(client.hasTarget(target), false);
    const retry = client.sendTarget(target);
    assert.equal(requests.length, 4);
    assert.equal(requests[3].body.clientSeq, 2);
    requests[3].resolve({ data: status(3, { accepted: true }) });
    assert.equal(await retry, true);
});

test('timeout retry retains identity and sequence', async () => {
    const { client, requests, enable } = setup();
    await enable();
    const write = client.sendTarget({ kind: 'white', profile: 'action' });
    requests[1].reject({ code: 'ECONNABORTED' });
    await Promise.resolve();
    assert.deepEqual(requests[1].body, requests[2].body);
    requests[2].resolve({ data: status(2, { accepted: true }) });
    assert.equal(await write, true);
});

test('a superseded timeout is not retried', async () => {
    const { client, requests, enable } = setup();
    await enable();
    const first = client.sendTarget({ kind: 'mythos', color: 'green' });
    const second = client.sendTarget({ kind: 'white', profile: 'action' });
    requests[1].reject({ code: 'ECONNABORTED' });
    requests[2].resolve({ data: status(3, { accepted: true }) });
    await Promise.all([first, second]);
    assert.equal(requests.length, 3);
});

test('the same target is not resent for another track in the same phase', async () => {
    const { client, requests, enable } = setup();
    await enable();
    const target = { kind: 'white', profile: 'encounters' };
    const first = client.sendTarget(target);
    assert.equal(await client.sendTarget({ ...target }), true);
    requests[1].resolve({ data: status(2, { accepted: true }) });
    await first;
    await client.sendTarget(target);
    assert.equal(requests.length, 2);
});

test('disable invalidates in-flight results and sends no white/off command', async () => {
    const { client, requests, enable } = setup();
    await enable();
    const write = client.sendTarget({ kind: 'mythos', color: 'blue' });
    const unlink = client.disable();
    assert.equal(client.state.linked, false);
    assert.deepEqual(requests[2].body, { enabled: false, controlEpoch: 'secret-epoch' });
    requests[2].resolve({ data: status(4, { enabled: false, controlGeneration: null, stage: 'cancelled' }) });
    await unlink;
    requests[1].resolve({ data: status(3, { accepted: true, stage: 'scene_active' }) });
    await write;
    assert.equal(client.state.stage, 'cancelled');
    assert.equal(client.state.linked, false);
});

test('another tab taking control revokes local ownership without exposing its epoch', async () => {
    const { client, requests, enable } = setup();
    await enable();
    const read = client.poll();
    requests[1].resolve({ data: status(3, { controlGeneration: 'owner-2' }) });
    await read;
    assert.equal(client.state.linked, false);
    assert.equal(client.state.error, 'control_lost');
    assert.equal(await client.sendTarget({ kind: 'white', profile: 'action' }), false);
});

test('network failures resolve safely and allow an explicit retry of the same choice', async () => {
    const { client, requests, enable } = setup();
    await enable();
    const target = { kind: 'white', profile: 'action' };
    const write = client.sendTarget(target);
    requests[1].reject(new Error('network'));
    await Promise.resolve();
    requests[2].reject(new Error('network'));
    assert.equal(await write, false);
    assert.equal(client.state.error, 'offline');
    const retry = client.sendTarget(target);
    requests[3].resolve({ data: status(3, { accepted: true }) });
    await retry;
    assert.equal(client.state.error, null);
});

test('artwork buttons retain ordered aliases and accessible names', () => {
    assert.deepEqual(MYTHOS_CARD_BUTTONS.map(c => [c.id, c.label]), [
        ['green', 'Green'], ['yellow', 'Yellow'], ['blue', 'Blue'],
    ]);
});

test('explicit choices share one acquisition and send only the latest full target', async () => {
    const { client, requests } = setup();
    const first = client.requestTarget({ kind: 'mythos', mythosSessionId: 'session', color: null });
    const blue = { kind: 'mythos', mythosSessionId: 'session', color: 'blue' };
    assert.equal(client.requestTarget(blue), first);
    const action = { kind: 'white', profile: 'action' };
    assert.equal(client.requestTarget(action), first);
    assert.equal(requests.length, 1);
    requests[0].resolve({ data: status(1, { controlEpoch: 'epoch' }) });
    await flush();
    assert.equal(requests.length, 2);
    assert.deepEqual(requests[1].body.target, action);
    assert.equal(requests[1].body.clientSeq, 1);
    requests[1].resolve({ data: status(2, { accepted: true }) });
    assert.equal(await first, true);
    assert.equal(await client.requestTarget(action), true);
    assert.equal(requests.length, 2);
});

test('an early Mythos color keeps its session and replaces darkness before acquisition', async () => {
    const { client, requests } = setup();
    const first = client.requestTarget({ kind: 'mythos', mythosSessionId: 'session', color: null });
    const blue = { kind: 'mythos', mythosSessionId: 'session', color: 'blue' };
    client.requestTarget(blue);
    assert.equal(client.hasTarget(blue), true);
    requests[0].resolve({ data: status(1, { controlEpoch: 'epoch' }) });
    await flush();
    assert.deepEqual(requests[1].body.target, blue);
    requests[1].resolve({ data: status(2, { accepted: true }) });
    await first;
});

test('a non-acquiring target request cannot acquire but can replace an existing pending target', async () => {
    const { client, requests } = setup();
    const action = { kind: 'white', profile: 'action' };
    assert.equal(await client.requestTarget(action, { acquire: false }), false);
    assert.equal(requests.length, 0);
    const first = client.requestTarget({ kind: 'mythos', mythosSessionId: 'session', color: null });
    assert.equal(client.requestTarget(action, { acquire: false }), first);
    requests[0].resolve({ data: status(1, { controlEpoch: 'epoch' }) });
    await flush();
    assert.equal(requests.length, 2);
    assert.deepEqual(requests[1].body.target, action);
    requests[1].resolve({ data: status(2, { accepted: true }) });
    await first;
});

test('a failed acquisition discards its choice and retries only on a new gesture', async () => {
    const { client, requests } = setup();
    const first = client.requestTarget({ kind: 'mythos', mythosSessionId: 'old', color: 'blue' });
    requests[0].reject({ code: 'ECONNABORTED' });
    assert.equal(await first, false);
    assert.equal(client.state.controlPending, false);
    assert.equal(requests.length, 1);
    const read = client.poll();
    requests[1].resolve({ data: status(2) });
    await read;
    assert.equal(await client.requestTarget({ kind: 'white', profile: 'action' }, { acquire: false }), false);
    assert.equal(requests.length, 2);
    const target = { kind: 'white', profile: 'encounters' };
    const next = client.requestTarget(target);
    requests[2].resolve({ data: status(3, { controlEpoch: 'new-epoch' }) });
    await flush();
    assert.deepEqual(requests[3].body.target, target);
    requests[3].resolve({ data: status(4, { accepted: true }) });
    assert.equal(await next, true);
});

test('takeover during acquisition never causes a background retry or stale target send', async () => {
    const { client, requests } = setup();
    const first = client.requestTarget({ kind: 'mythos', mythosSessionId: 'session', color: 'blue' });
    const read = client.poll();
    requests[1].resolve({ data: status(5, { controlGeneration: 'other-owner' }) });
    await read;
    requests[0].resolve({ data: status(1, { controlEpoch: 'stale-epoch' }) });
    assert.equal(await first, false);
    assert.equal(client.state.error, 'control_lost');
    assert.equal(await client.requestTarget({ kind: 'white', profile: 'action' }, { acquire: false }), false);
    assert.equal(requests.length, 2);
    const next = client.requestTarget({ kind: 'white', profile: 'action' });
    requests[2].resolve({ data: status(6, { controlEpoch: 'new-epoch', controlGeneration: 'new-owner' }) });
    await flush();
    assert.equal(requests[3].body.controlEpoch, 'new-epoch');
    requests[3].resolve({ data: status(7, { accepted: true, controlGeneration: 'new-owner' }) });
    await next;
});

test('disable and logout discard pending choices and release a late epoch exactly once', async () => {
    for (const method of ['disable', 'releaseForLogout']) {
        const { client, requests } = setup();
        const target = { kind: 'white', profile: 'action' };
        const choice = client.requestTarget(target);
        const release = client[method]();
        assert.equal(client[method](), release);
        assert.equal(await client.requestTarget(target), false);
        requests[0].resolve({ data: status(1, { controlEpoch: 'late-epoch' }) });
        assert.equal(await choice, false);
        assert.equal(requests.length, 2);
        assert.deepEqual(requests[1].body, { enabled: false, controlEpoch: 'late-epoch' });
        requests[1].resolve({ data: status(2, { enabled: false, controlGeneration: null }) });
        assert.equal(await release, true);
        assert.equal(client.state.linked, false);
        assert.equal(client.state.controlPending, false);
        assert.equal(requests.some(request => request.url === '/lighting/intents'), false);
        if (method === 'releaseForLogout') {
            assert.equal(await client.requestTarget(target), false);
            assert.equal(requests.length, 2);
        }
    }
});

test('a failed late acquisition after logout creates no release or intent retry', async () => {
    const { client, requests } = setup();
    const choice = client.requestTarget({ kind: 'white', profile: 'action' });
    const logout = client.releaseForLogout();
    requests[0].reject({ code: 'ECONNABORTED' });
    assert.equal(await choice, false);
    assert.equal(await logout, false);
    assert.equal(client.state.controlPending, false);
    assert.equal(await client.requestTarget({ kind: 'white', profile: 'action' }), false);
    assert.equal(requests.length, 1);
});

test('an authenticated remount after logout stays read-only and permits a new explicit choice', async () => {
    const { client, requests } = setup();
    await client.releaseForLogout();
    client.startPolling();
    client.stopPolling();
    requests[0].resolve({ data: status(1, { enabled: false, controlGeneration: null }) });
    await flush();
    assert.deepEqual(requests.map(request => request.method), ['get']);
    const choice = client.requestTarget({ kind: 'white', profile: 'action' });
    requests[1].resolve({ data: status(2, { controlEpoch: 'epoch' }) });
    await flush();
    requests[2].resolve({ data: status(3, { accepted: true }) });
    await choice;
});

test('game gestures start audio synchronously and navigation after takeover cannot reacquire', async () => {
    const { client, requests } = setup();
    const played = [];
    const audio = { state: { playingFolder: null },
        play(options) { played.push(options.folderSlug); this.state.playingFolder = options.folderSlug; } };
    const flow = createGameFlowController({ audio, lighting: client, uuid: () => 'session' });
    flow.enterMythos();
    flow.selectMythosColor('blue', flow.state.mythosSessionId);
    assert.deepEqual(played, ['mythos']);
    assert.equal(requests.length, 1);
    requests[0].resolve({ data: status(1, { controlEpoch: 'epoch' }) });
    await flush();
    assert.equal(requests[1].body.target.color, 'blue');
    requests[1].resolve({ data: status(2, { accepted: true }) });
    await flush();
    const read = client.poll();
    requests[2].resolve({ data: status(3, { controlGeneration: 'other-owner' }) });
    await read;
    flow.observeNavigation('/other');
    assert.equal(flow.state.mythosSessionId, 'session');
    assert.equal(flow.state.selectedMythosColor, 'blue');
    assert.equal(requests.length, 3);
});

test('navigation during Mythos acquisition preserves the selected scene as the pending target', async () => {
    const { client, requests } = setup();
    const audio = { state: { playingFolder: null },
        play(options) { this.state.playingFolder = options.folderSlug; } };
    const flow = createGameFlowController({ audio, lighting: client, uuid: () => 'session' });
    flow.observeNavigation('/mythos');
    assert.equal(requests.length, 0);
    flow.enterMythos();
    flow.selectMythosColor('blue', flow.state.mythosSessionId);
    flow.enterEncounters();
    flow.observeNavigation('/other');
    requests[0].resolve({ data: status(1, { controlEpoch: 'epoch' }) });
    await flush();
    assert.deepEqual(requests[1].body.target, { kind: 'mythos', mythosSessionId: 'session', color: 'blue' });
    requests[1].resolve({ data: status(2, { accepted: true }) });
    await flush();
});

test('pure navigation, including Encounters and reload, never acquires lighting control', async () => {
    const { client, requests } = setup();
    const flow = createGameFlowController({ audio: { state: {} }, lighting: client });
    flow.observeNavigation('/other');
    assert.equal(requests.length, 0);
    flow.enterEncounters();
    for (const url of ['/encounters', '/encounters/general', '/other', '/', '/mythos', '/other?refresh=1']) {
        flow.observeNavigation(url);
    }
    await flush();
    assert.equal(requests.length, 0);
    assert.equal(flow.state.selectedContext, null);
});

test('navigation cannot replace a pending Action lighting target with Encounters', async () => {
    const { client, requests } = setup();
    const audio = { state: { playingFolder: null },
        play(options) { this.state.playingFolder = options.folderSlug; } };
    const flow = createGameFlowController({ audio, lighting: client });
    flow.selectAction();
    flow.enterEncounters();
    flow.observeNavigation('/encounters');
    flow.observeNavigation('/other/quest');
    assert.equal(audio.state.playingFolder, 'action');
    assert.equal(requests.length, 1);
    requests[0].resolve({ data: status(1, { controlEpoch: 'epoch' }) });
    await flush();
    assert.equal(requests.length, 2);
    assert.deepEqual(requests[1].body.target, { kind: 'white', profile: 'action' });
    assert.equal(requests[1].body.clientSeq, 1);
    requests[1].resolve({ data: status(2, { accepted: true }) });
    await flush();
});

test('only a terminal music choice after leaving Mythos sends the Encounters lighting intent', async () => {
    const { client, requests } = setup();
    const audio = { state: { playingFolder: null },
        play(options) { this.state.playingFolder = options.folderSlug; } };
    const flow = createGameFlowController({ audio, lighting: client, uuid: () => 'session' });
    flow.enterMythos();
    flow.observeNavigation('/mythos');
    requests[0].resolve({ data: status(1, { controlEpoch: 'epoch' }) });
    await flush();
    requests[1].resolve({ data: status(2, { accepted: true }) });
    await flush();
    flow.enterEncounters();
    flow.observeNavigation('/encounters');
    flow.observeNavigation('/other');
    assert.equal(requests.length, 2);
    assert.equal(audio.state.playingFolder, 'mythos');
    assert.equal(flow.state.mythosSessionId, 'session');
    flow.playUserChoice({ folderSlug: 'special/victory' }, 'other');
    assert.equal(requests.length, 3);
    assert.deepEqual(requests[2].body.target, { kind: 'white', profile: 'encounters' });
    requests[2].resolve({ data: status(3, { accepted: true }) });
    await flush();
    assert.equal(audio.state.playingFolder, 'special/victory');
    assert.equal(flow.state.mythosSessionId, null);
});
