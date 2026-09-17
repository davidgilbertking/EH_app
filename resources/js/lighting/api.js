import { createUuid } from '../utils/uuid.js';

const KNOWN_ERRORS = new Set([
    'offline', 'worker_unavailable', 'not_configured', 'preset_not_configured',
    'unsupported_transition', 'stale', 'control_lost', 'transport_timeout',
    'configuration_error', 'intent_conflict', 'validation_error',
]);

export function createLightingState() {
    return {
        canControl: false,
        linked: false, remoteEnabled: false, controlPending: false,
        driver: 'mock', simulated: true, controlGeneration: null,
        revision: 0, appliedRevision: null, statusVersion: -1,
        stage: 'idle', observed: null, target: null, error: null,
        worker: null, journal: [],
    };
}

// An epoch lives only in this tab's memory. Reading status never acquires control
// or replays an intent, including after reload and Inertia history navigation.
export function createLightingClient({
    http, state = createLightingState(), uuid = createUuid,
    schedule = setTimeout, unschedule = clearTimeout, pollIntervalMs = 1000,
    canControl = false,
}) {
    state.canControl = canControl === true;
    let accessUserId = null;
    let epoch = null;
    let seq = 0;
    let lifecycle = 0;
    let timer = null;
    let polling = false;
    let pollingGeneration = 0;
    let pollInFlight = null;
    let acquireRequest = null;
    let acquireOperation = null;
    let userAcquisition = null;
    let pendingTarget = null;
    let targetGeneration = 0;
    let releaseOperation = null;
    let loggedOut = false;
    let lastTargetKey = null;

    // Permission comes from the authenticated page. Revocation/account changes
    // discard pending work locally; only the server can authorize lamp access.
    function setAccess(allowed, userId = null) {
        allowed = allowed === true;
        if (state.canControl === allowed && accessUserId === userId) return;
        accessUserId = userId;
        lifecycle++;
        stopPolling();
        discardPendingTarget();
        forgetControl();
        acquireOperation = null;
        acquireRequest = null;
        releaseOperation = null;
        Object.assign(state, createLightingState(), { canControl: allowed });
        loggedOut = !allowed;
    }

    function discardPendingTarget() {
        targetGeneration++;
        pendingTarget = null;
        userAcquisition = null;
    }

    function safeError(error) {
        const code = error?.response?.data?.error;
        if (KNOWN_ERRORS.has(code)) return code;
        if (error?.response?.status === 422) return 'validation_error';
        if ([401, 403, 419].includes(error?.response?.status)) return 'control_lost';
        return error?.code === 'ECONNABORTED' ? 'transport_timeout' : 'offline';
    }

    function forgetControl() {
        epoch = null;
        state.linked = false;
        lastTargetKey = null;
    }

    function applyStatus(data) {
        if (!Number.isSafeInteger(data?.statusVersion) || data.statusVersion < state.statusVersion) return false;
        if (state.linked && (!data.enabled || data.controlGeneration !== state.controlGeneration)) {
            discardPendingTarget();
            forgetControl();
            state.error = 'control_lost';
        }
        for (const key of ['driver', 'simulated', 'revision', 'appliedRevision', 'statusVersion',
            'stage', 'observed', 'target', 'worker', 'journal']) {
            if (key in data) state[key] = data[key];
        }
        state.remoteEnabled = Boolean(data.enabled);
        state.controlGeneration = data.controlGeneration ?? null;
        if (state.error !== 'control_lost') state.error = data.error ?? null;
        if (state.error && state.error !== 'worker_unavailable') lastTargetKey = null;
        return true;
    }

    async function poll() {
        if (!state.canControl) return;
        // At most one status read in flight; ordered server versions also protect
        // against a read overtaken by an intent/control response.
        if (pollInFlight) return pollInFlight;
        const generation = lifecycle;
        const versionAtStart = state.statusVersion;
        pollInFlight = (async () => {
            try {
                const { data } = await http.get('/lighting/status', { timeout: 5000 });
                if (generation === lifecycle) applyStatus(data);
            } catch (error) {
                if (generation === lifecycle && state.statusVersion === versionAtStart) {
                    state.error = safeError(error);
                    if (state.error === 'control_lost') {
                        discardPendingTarget();
                        forgetControl();
                    }
                }
            } finally {
                pollInFlight = null;
            }
        })();
        return pollInFlight;
    }

    function enable() {
        if (!state.canControl || loggedOut || releaseOperation) return Promise.resolve(false);
        if (acquireOperation) return acquireOperation;
        if (state.controlPending) return Promise.resolve(false);
        const operation = acquireControl();
        acquireOperation = operation;
        void operation.finally(() => {
            if (acquireOperation === operation) acquireOperation = null;
        });
        return operation;
    }

    async function acquireControl() {
        state.controlPending = true;
        const generation = ++lifecycle;
        forgetControl();
        state.error = null;
        let request = null;
        try {
            request = http.post('/lighting/control', { enabled: true }, { timeout: 5000 });
            acquireRequest = request;
            const { data } = await request;
            if (generation !== lifecycle) return false;
            applyStatus(data);
            // A newer poll may already have observed a takeover or revocation.
            // Its generation wins even when this response includes a valid epoch.
            if (!data.enabled || !state.remoteEnabled
                || typeof data.controlEpochGeneration !== 'string'
                || data.controlEpochGeneration !== data.controlGeneration
                || data.controlEpochGeneration !== state.controlGeneration) {
                state.error = 'control_lost';
                return false;
            }
            if (typeof data.controlEpoch !== 'string') throw new Error('Missing control epoch');
            epoch = data.controlEpoch;
            seq = 0;
            state.linked = true;
            return true;
        } catch (error) {
            if (generation === lifecycle) state.error = safeError(error);
            return false;
        } finally {
            if (acquireRequest === request) acquireRequest = null;
            if (generation === lifecycle) state.controlPending = false;
        }
    }

    // Only explicit gestures may start acquisition. A route completion can
    // replace a pending gesture's target, but cannot acquire on its own.
    function requestTarget(target, { acquire = true } = {}) {
        if (!state.canControl || loggedOut || releaseOperation) return Promise.resolve(false);
        if (userAcquisition) {
            pendingTarget = target;
            return userAcquisition.promise;
        }
        if (epoch && state.linked && !state.controlPending) return sendTarget(target);
        if (!acquire) return Promise.resolve(false);
        pendingTarget = target;
        const generation = ++targetGeneration;
        const operation = { promise: null };
        userAcquisition = operation;
        operation.promise = (async () => {
            try {
                const enabled = await enable();
                if (generation !== targetGeneration || !enabled) return false;
                const latest = pendingTarget;
                pendingTarget = null;
                userAcquisition = null;
                return await sendTarget(latest);
            } finally {
                if (userAcquisition === operation) {
                    userAcquisition = null;
                    pendingTarget = null;
                }
            }
        })();
        return operation.promise;
    }

    async function sendTarget(target) {
        if (!state.canControl || !epoch || !state.linked || state.controlPending) return false;
        const targetKey = JSON.stringify(target);
        if (hasTarget(target)) return true;
        lastTargetKey = targetKey;
        const generation = lifecycle;
        const payload = { intentId: uuid(), controlEpoch: epoch, clientSeq: ++seq, target };
        const current = () => generation === lifecycle && payload.clientSeq === seq && epoch === payload.controlEpoch;
        // One transport retry uses the SAME identity. A newer choice prevents an
        // old timed-out request from being retried or changing the displayed state.
        for (let attempt = 0; attempt < 2; attempt++) {
            try {
                const { data } = await http.post('/lighting/intents', payload, { timeout: 5000 });
                if (!current()) return false;
                applyStatus(data);
                if (!data.accepted) {
                    lastTargetKey = null;
                    if (data.error) state.error = data.error;
                }
                return Boolean(data.accepted);
            } catch (error) {
                if (!current()) return false;
                if (attempt === 0 && !error?.response) continue;
                state.error = safeError(error);
                lastTargetKey = null;
                if (state.error === 'control_lost') {
                    discardPendingTarget();
                    forgetControl();
                }
                return false;
            }
        }
        return false;
    }

    async function pollLoop(generation) {
        await poll();
        if (polling && generation === pollingGeneration) timer = schedule(() => pollLoop(generation), pollIntervalMs);
    }

    function startPolling() {
        if (!state.canControl || polling) return;
        // A newly mounted authenticated layout permits new gestures after a
        // previous logout. Its bootstrap remains strictly read-only.
        if (!releaseOperation) loggedOut = false;
        polling = true;
        void pollLoop(++pollingGeneration);
    }

    function stopPolling() {
        polling = false;
        pollingGeneration++;
        if (timer !== null) unschedule(timer);
        timer = null;
    }

    function disable() {
        discardPendingTarget();
        if (!state.canControl) return Promise.resolve(false);
        if (releaseOperation) return releaseOperation;
        const operation = releaseControl();
        releaseOperation = operation;
        void operation.finally(() => {
            if (releaseOperation === operation) releaseOperation = null;
        });
        return operation;
    }

    async function releaseControl() {
        const pendingAcquisition = acquireRequest;
        const generation = ++lifecycle;
        let releaseEpoch = epoch;
        forgetControl();
        state.controlPending = true;
        try {
            // Invalidate acquisition immediately, then release its late epoch.
            if (pendingAcquisition) {
                const { data } = await pendingAcquisition;
                releaseEpoch = data.controlEpoch ?? releaseEpoch;
            }
            if (releaseEpoch && state.canControl && generation === lifecycle) {
                const { data } = await http.post('/lighting/control', {
                    enabled: false, controlEpoch: releaseEpoch,
                }, { timeout: 5000 });
                if (generation === lifecycle) applyStatus(data);
            }
            return true;
        } catch (error) {
            // Logout also revokes control server-side when the response is lost.
            if (generation === lifecycle) state.error = safeError(error);
            return false;
        } finally {
            if (generation === lifecycle) state.controlPending = false;
        }
    }

    function releaseForLogout() {
        loggedOut = true;
        stopPolling();
        return disable();
    }

    function hasTarget(target) {
        if (!state.canControl) return false;
        if (userAcquisition && JSON.stringify(pendingTarget) === JSON.stringify(target)) return true;
        return state.linked && (!state.error || state.error === 'worker_unavailable')
            && lastTargetKey === JSON.stringify(target);
    }

    return { state, setAccess, enable, disable, requestTarget, sendTarget, hasTarget, poll, startPolling, stopPolling, releaseForLogout };
}
