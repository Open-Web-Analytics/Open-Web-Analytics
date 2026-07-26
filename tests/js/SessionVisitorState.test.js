import { OWATracker } from '../../modules/base/src/tracker/Tracker.js';
import { OWA_instance as OWA } from '../../modules/base/src/common/owa.js';
import { Event } from '../../modules/base/src/tracker/Event.js';

/**
 * Session / visitor identity state machine.
 *
 * This is the core "who is this and is this a fresh visit?" machinery that runs
 * on every event. The moving parts, all driven off the persistent 'v' (visitor,
 * ~1yr) and 's' (session) state stores:
 *
 *   - isNewSession(timestamp, last_req): a visit is a NEW session when the gap
 *     since the last request reaches sessionLength (default 1800s). Strictly
 *     less than the window -> active; at or beyond -> new. A missing last_req is
 *     treated as 0, so the very first request is always a new session.
 *
 *   - setVisitorId(): reads v.vid. If absent, it first tries to migrate a LEGACY
 *     cookie where the whole 'v' store was a bare id string (not a {vid:...}
 *     object) -- that value is lifted into v.vid and the visitor is NOT counted
 *     as new. Only when there is genuinely no id does it mint a guid and flag
 *     is_new_visitor. The resolved id is persisted and promoted to visitor_id.
 *
 *   - setSessionId(): on a new session it records prior_session_id (from the old
 *     s.sid), resets the session store, mints a new session guid, and raises
 *     is_new_session + isNewSessionFlag. On an active session it just reuses the
 *     stored s.sid. A fail-safe mints one if somehow still empty.
 *
 *   - setNumberPriorSessions(): only touches nps on a new session. Existing nps
 *     is incremented and persisted; a first-ever session leaves nps unset in the
 *     store (the "0" branch) but still stamps the property.
 *
 *   - setFirstSessionTimestamp(): stamps v.fsts once (first visit) and always
 *     recomputes dsfs (days since first session) against the event timestamp.
 *
 *   - setDaysSinceLastSession(): on a new session computes dsps in whole days
 *     from last_req; otherwise carries the stored s.dsps forward.
 *
 *   - setLastRequestTime(): promotes the PRIOR last_req to a global property
 *     (so downstream new-session math sees the old value) and then stores the
 *     current event timestamp as the new last_req.
 *
 *   - resetSessionState(): clears the whole 's' store but deliberately preserves
 *     last_req so session-expiry math still works right after a reset.
 *
 * jsdom's cookie jar backs the state stores here, so these assert real
 * round-trips through OWA.getState/setState, not mocks.
 */

function setDocumentDomain(domain) {
    Object.defineProperty(document, 'domain', {
        configurable: true,
        get() { return domain; },
    });
}

function newTracker() {
    const t = new OWATracker({ cookie_domain_set: true, cookie_domain: '.cv.example' });
    t.setSiteId('session-site');
    return t;
}

function eventAt(timestamp) {
    const e = new Event();
    if (timestamp) {
        e.set('timestamp', timestamp);
    }
    return e;
}

// A fixed reference "now" so day math is exact and deterministic.
const NOW = 1700000000;
const DAY = 3600 * 24;

beforeEach(() => {
    setDocumentDomain('cv.example');
    OWA.setSetting('ns', 'owa_');
    OWA.setSetting('cookie_domain', '.cv.example');
    OWA.setSetting('hashCookiesToDomain', false);
    OWA.setSetting('loggerPause', false);
    ['v', 's', 'c', 'b'].forEach((store) => OWA.clearState(store));
});

afterEach(() => {
    ['v', 's', 'c', 'b'].forEach((store) => OWA.clearState(store));
});

describe('isNewSession (sessionLength expiry boundary)', () => {

    test('a gap shorter than sessionLength is an active session', () => {
        const t = newTracker();
        expect(t.options.sessionLength).toBe(1800);
        // 0s and 1799s gaps are both inside the 1800s window.
        expect(t.isNewSession(NOW, NOW)).toBe(false);
        expect(t.isNewSession(NOW + 1799, NOW)).toBe(false);
    });

    test('a gap of exactly sessionLength (or more) starts a new session', () => {
        const t = newTracker();
        // 1800s is NOT strictly less than the window -> new session.
        expect(t.isNewSession(NOW + 1800, NOW)).toBe(true);
        expect(t.isNewSession(NOW + 5000, NOW)).toBe(true);
    });

    test('a missing last_req is treated as 0, so the first request is new', () => {
        const t = newTracker();
        expect(t.isNewSession(NOW, null)).toBe(true);
    });
});

describe('setVisitorId', () => {

    test('mints a visitor id and flags is_new_visitor when none is stored', () => {
        const t = newTracker();

        t.setVisitorId(eventAt(NOW), null);

        const vid = t.getGlobalEventProperty('visitor_id');
        expect(vid).toBeTruthy();
        expect(t.getGlobalEventProperty('is_new_visitor')).toBe(true);
        // The freshly minted id is persisted for the next visit.
        expect(OWA.getState('v', 'vid')).toBe(vid);
    });

    test('reuses the stored visitor id and does not flag a new visitor', () => {
        const t = newTracker();
        OWA.setState('v', 'vid', 'existing-vid-123', true);

        t.setVisitorId(eventAt(NOW), null);

        expect(t.getGlobalEventProperty('visitor_id')).toBe('existing-vid-123');
        expect(t.getGlobalEventProperty('is_new_visitor')).toBeUndefined();
    });

    test('migrates a legacy bare-string v store into v.vid without counting a new visitor', () => {
        const t = newTracker();
        // Old cookie format: the whole 'v' store was the id itself, not {vid:...}.
        OWA.initializeStateManager();
        OWA.state.stores['v'] = 'legacy-bare-guid';

        t.setVisitorId(eventAt(NOW), null);

        expect(t.getGlobalEventProperty('visitor_id')).toBe('legacy-bare-guid');
        // A migrated id is a returning visitor, not a new one.
        expect(t.getGlobalEventProperty('is_new_visitor')).toBeUndefined();
        // ...and it is rehomed under the modern v.vid key.
        expect(OWA.getState('v', 'vid')).toBe('legacy-bare-guid');
    });

    test('runs the callback with the event when provided', () => {
        const t = newTracker();
        const event = eventAt(NOW);
        let received = null;

        t.setVisitorId(event, (e) => { received = e; });

        expect(received).toBe(event);
    });
});

describe('setSessionId', () => {

    test('mints a new session id and raises the new-session flags on a fresh session', () => {
        const t = newTracker();
        // No last_req global -> isNewSession is true.
        t.setSessionId(eventAt(NOW), null);

        expect(t.getGlobalEventProperty('session_id')).toBeTruthy();
        expect(t.getGlobalEventProperty('is_new_session')).toBe(true);
        expect(t.isNewSessionFlag).toBe(true);
        // Persisted for the rest of the session.
        expect(OWA.getState('s', 'sid')).toBe(t.getGlobalEventProperty('session_id'));
    });

    test('records the prior session id when a new session succeeds an old one', () => {
        const t = newTracker();
        OWA.setState('s', 'sid', 'old-session-id', true);
        // last_req far in the past -> new session.
        t.setGlobalEventProperty('last_req', NOW - 5000);

        t.setSessionId(eventAt(NOW), null);

        expect(t.getGlobalEventProperty('prior_session_id')).toBe('old-session-id');
        // A brand new session id replaced the old one.
        expect(t.getGlobalEventProperty('session_id')).not.toBe('old-session-id');
        expect(t.getGlobalEventProperty('is_new_session')).toBe(true);
    });

    test('reuses the stored session id (no new-session flag) during an active session', () => {
        const t = newTracker();
        OWA.setState('s', 'sid', 'active-session-1', true);
        // last_req is "now" -> gap 0 -> active session.
        t.setGlobalEventProperty('last_req', NOW);

        t.setSessionId(eventAt(NOW), null);

        expect(t.getGlobalEventProperty('session_id')).toBe('active-session-1');
        expect(t.getGlobalEventProperty('is_new_session')).toBeUndefined();
    });
});

describe('setNumberPriorSessions', () => {

    test('increments and persists nps on a new session', () => {
        const t = newTracker();
        OWA.setState('v', 'nps', '2', true);
        t.isNewSessionFlag = true;

        t.setNumberPriorSessions(eventAt(NOW), null);

        expect(t.getGlobalEventProperty('nps')).toBe(3);
        expect(OWA.getState('v', 'nps')).toBe(3);
    });

    test('leaves nps untouched during an active (not new) session', () => {
        const t = newTracker();
        OWA.setState('v', 'nps', '4', true);
        t.isNewSessionFlag = false;

        t.setNumberPriorSessions(eventAt(NOW), null);

        // Not a new session: the stored value rides along unchanged.
        expect(t.getGlobalEventProperty('nps')).toBe('4');
        expect(OWA.getState('v', 'nps')).toBe('4');
    });
});

describe('setFirstSessionTimestamp', () => {

    test('stamps fsts on the first visit and reports zero days since', () => {
        const t = newTracker();

        t.setFirstSessionTimestamp(eventAt(NOW), null);

        expect(t.getGlobalEventProperty('fsts')).toBe(NOW);
        expect(t.getGlobalEventProperty('dsfs')).toBe(0);
        expect(OWA.getState('v', 'fsts')).toBe(NOW);
    });

    test('preserves an existing fsts and computes days since first session', () => {
        const t = newTracker();
        OWA.setState('v', 'fsts', NOW - 3 * DAY, true);

        t.setFirstSessionTimestamp(eventAt(NOW), null);

        // fsts is sticky; only dsfs advances.
        expect(t.getGlobalEventProperty('fsts')).toBe(NOW - 3 * DAY);
        expect(t.getGlobalEventProperty('dsfs')).toBe(3);
    });
});

describe('setDaysSinceLastSession', () => {

    test('computes dsps in whole days from last_req on a new session', () => {
        const t = newTracker();
        t.setGlobalEventProperty('is_new_session', true);
        t.setGlobalEventProperty('last_req', NOW - 5 * DAY);

        t.setDaysSinceLastSession(eventAt(NOW), null);

        expect(t.getGlobalEventProperty('dsps')).toBe(5);
    });

    test('carries the stored dsps forward during an active session', () => {
        const t = newTracker();
        OWA.setState('s', 'dsps', 7);
        // is_new_session not set -> falls back to the stored value.
        t.setDaysSinceLastSession(eventAt(NOW), null);

        expect(t.getGlobalEventProperty('dsps')).toBe(7);
    });
});

describe('setLastRequestTime', () => {

    test('promotes the prior last_req then stores the current timestamp', () => {
        const t = newTracker();
        OWA.setState('s', 'last_req', NOW - 1000, true);

        t.setLastRequestTime(eventAt(NOW), null);

        // The OLD value rides this event (so new-session math sees the gap)...
        expect(t.getGlobalEventProperty('last_req')).toBe(NOW - 1000);
        // ...while the store is advanced to now for the next request.
        expect(OWA.getState('s', 'last_req')).toBe(NOW);
    });
});

describe('resetSessionState', () => {

    test('clears the session store but preserves last_req', () => {
        const t = newTracker();
        OWA.setState('s', 'last_req', NOW - 1, true);
        OWA.setState('s', 'sid', 'old-sid', true);
        OWA.setState('s', 'source', 'news', true);

        t.resetSessionState();

        // last_req survives so isNewSession still has a reference point...
        expect(OWA.getState('s', 'last_req')).toBe(NOW - 1);
        // ...everything else in the session store is wiped.
        expect(OWA.getState('s', 'sid')).toBeFalsy();
        expect(OWA.getState('s', 'source')).toBeFalsy();
    });
});
