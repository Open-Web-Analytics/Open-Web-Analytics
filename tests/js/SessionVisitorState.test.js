import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';
import { OwaEvent } from '../../modules/Base/src/tracker/OwaEvent.js';

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
 *     PERSISTED s.sid), announces the decision as 'isSessionizationDone', mints
 *     a new session guid and raises is_new_session + isNewSessionFlag. On an
 *     active session it reuses the stored s.sid, which the announcement has by
 *     then hydrated into memory. A fail-safe mints one if somehow still empty.
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
 *   - the 'isSessionizationDone' announcement, which settles the session store:
 *     a new session discards the PERSISTED store and keeps memory (holding only
 *     what this page load set); a continuing one merges the persisted values in
 *     behind memory, filling gaps only. This replaced resetSessionState(), which
 *     cleared the store and then put back what the page load had set, because by
 *     then the two were mixed and nothing distinguished them.
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
    const e = new OwaEvent();
    if (timestamp) {
        e.set('timestamp', timestamp);
    }
    return e;
}

// A fixed reference "now" so day math is exact and deterministic.
const NOW = 1700000000;
const DAY = 3600 * 24;

/** The state a fresh page load starts in: nothing in memory, nothing settled. */
function coldPage() {
    OWA.initializeStateManager();
    OWA.state.stores = {};
    OWA.state.storeFormats = {};
    OWA.state.hydrated = {};
    OWA.state.persistenceReleased = {};
}

/**
 * Write session values to the COOKIE the way a previous page load would have,
 * then wipe memory.
 *
 * Tests that need a PREVIOUS session's values must persist them rather than
 * set them: the session store is no longer hydrated on first touch, so a plain
 * setState() puts a value in memory, where it reads as something THIS page load
 * set. That distinction is the whole point of the design, and a test that seeds
 * with setState() is testing the wrong side of it.
 */
function seedPersistedSession(values) {
    coldPage();
    // Written into the cookie CACHE the state manager reads through, not
    // through document.cookie: jsdom refuses cookies whose domain does not
    // match the test document URL, and this suite runs on '.cv.example'. A
    // real round-trip would seed nothing and every assertion below would pass
    // or fail for the wrong reason.
    OWA.state.cookies = OWA.state.cookies || {};
    OWA.state.cookies['owa_s'] = [ JSON.stringify(values) ];
}

beforeEach(() => {
    setDocumentDomain('cv.example');
    OWA.setSetting('ns', 'owa_');
    OWA.setSetting('cookie_domain', '.cv.example');
    OWA.setSetting('hashCookiesToDomain', false);
    OWA.setSetting('loggerPause', false);
    ['v', 's', 'c', 'b', 'd'].forEach((store) => OWA.clearState(store));
    coldPage();
});

afterEach(() => {
    coldPage();
    ['v', 's', 'c', 'b', 'd'].forEach((store) => OWA.clearState(store));
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

        const vid = OWA.getState('v', 'vid');
        expect(vid).toBeTruthy();
        expect(OWA.getState('s', 'is_new_visitor')).toBe(true);
        // The freshly minted id is persisted for the next visit.
        expect(OWA.getState('v', 'vid')).toBe(vid);
    });

    test('reuses the stored visitor id and does not flag a new visitor', () => {
        const t = newTracker();
        OWA.setState('v', 'vid', 'existing-vid-123', true);

        t.setVisitorId(eventAt(NOW), null);

        expect(OWA.getState('v', 'vid')).toBe('existing-vid-123');
        expect(OWA.getState('s', 'is_new_visitor')).toBeFalsy();
    });

    test('migrates a legacy bare-string v store into v.vid without counting a new visitor', () => {
        const t = newTracker();
        // Old cookie format: the whole 'v' store was the id itself, not {vid:...}.
        OWA.initializeStateManager();
        OWA.state.stores['v'] = 'legacy-bare-guid';

        t.setVisitorId(eventAt(NOW), null);

        expect(OWA.getState('v', 'vid')).toBe('legacy-bare-guid');
        // A migrated id is a returning visitor, not a new one.
        expect(OWA.getState('s', 'is_new_visitor')).toBeFalsy();
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

        expect(OWA.getState('s', 'sid')).toBeTruthy();
        expect(OWA.getState('d', 'is_new_session')).toBe(true);
        expect(t.isNewSessionFlag).toBe(true);

        // The id lives in the store and is what rides events. It used to be
        // cached on the tracker as well, and this assertion used to compare the
        // two copies; with one copy left, comparing it to the event is what
        // still says something.
        const event = new OwaEvent();
        t.addGlobalPropertiesToEvent(event);
        expect(event.get('session_id')).toBe(OWA.getState('s', 'sid'));
    });

    test('records the prior session id when a new session succeeds an old one', () => {
        const t = newTracker();
        // Persisted, not set: the id of the session that just ended was written
        // by a previous page load.
        seedPersistedSession({ sid: 'old-session-id' });
        // last_req far in the past -> new session.
        OWA.setState('s', 'last_req', NOW - 5000);

        t.setSessionId(eventAt(NOW), null);

        expect(OWA.getState('s', 'prior_session_id')).toBe('old-session-id');
        // A brand new session id replaced the old one.
        expect(OWA.getState('s', 'sid')).not.toBe('old-session-id');
        expect(OWA.getState('d', 'is_new_session')).toBe(true);
    });

    test('the prior session id rides the event that started the new session', () => {
        // It is read out of the cookie moments before the boundary throws that
        // cookie away, so nothing can recover it afterwards -- which is why it
        // could not simply be collected from the persisted store like the rest.
        const t = newTracker();
        seedPersistedSession({ sid: 'old-sid', last_req: NOW - 5000 });
        OWA.setState('s', 'last_req', NOW - 5000);

        t.setSessionId(eventAt(NOW), null);

        const event = new OwaEvent();
        t.addGlobalPropertiesToEvent(event);

        expect(event.get('prior_session_id')).toBe('old-sid');
    });

    test('and keeps being reported for the rest of that session', () => {
        // The behaviour change from moving it into 's': it used to exist only
        // on the page load that crossed the boundary, because a global event
        // property dies with the page. A session's predecessor is a fact about
        // the session, so it now survives into the session's later pages.
        const t = newTracker();
        seedPersistedSession({
            sid: 'current-session',
            last_req: NOW,
            prior_session_id: 'the-one-before',
        });
        OWA.setState('s', 'last_req', NOW);

        t.setSessionId(eventAt(NOW), null);

        const event = new OwaEvent();
        t.addGlobalPropertiesToEvent(event);

        expect(event.get('session_id')).toBe('current-session');
        expect(event.get('prior_session_id')).toBe('the-one-before');
    });

    test('reuses the stored session id (no new-session flag) during an active session', () => {
        const t = newTracker();
        seedPersistedSession({ sid: 'active-session-1' });
        // last_req is "now" -> gap 0 -> active session.
        OWA.setState('s', 'last_req', NOW);

        t.setSessionId(eventAt(NOW), null);

        expect(OWA.getState('s', 'sid')).toBe('active-session-1');
        expect(OWA.getState('d', 'is_new_session')).toBeFalsy();
    });
});

describe('is_new_visitor has session lifetime', () => {

    /*
     * It says this session was the visitor's FIRST, not that this request
     * minted them. As a per-page global it vanished on the next page, so the
     * server derived is_repeat_visitor = true on page two of a visitor's very
     * first session -- while the session row it had just written still said
     * is_new_visitor. The store's lifetime is what makes the two agree.
     */

    test('it survives into a later page of the same session', () => {
        seedPersistedSession({
            sid: 'first-session',
            last_req: NOW,
            is_new_visitor: true,
        });
        OWA.setState('v', 'vid', 'known-visitor');
        const t = newTracker();
        OWA.setState('s', 'last_req', NOW);

        t.setVisitorId(eventAt(NOW), null);
        t.setSessionId(eventAt(NOW), null);

        const event = new OwaEvent();
        t.addGlobalPropertiesToEvent(event);

        expect(event.get('is_new_session')).toBeFalsy();
        expect(event.get('is_new_visitor')).toBe(true);
    });

    test('but not into the visitor\'s NEXT session', () => {
        // The boundary discards the persisted store, so a known visitor
        // starting a second session is not flagged as new.
        seedPersistedSession({
            sid: 'old-session',
            last_req: NOW - 5000,
            is_new_visitor: true,
        });
        OWA.setState('v', 'vid', 'known-visitor');
        const t = newTracker();
        OWA.setState('s', 'last_req', NOW - 5000);

        t.setVisitorId(eventAt(NOW), null);
        t.setSessionId(eventAt(NOW), null);

        const event = new OwaEvent();
        t.addGlobalPropertiesToEvent(event);

        expect(event.get('is_new_session')).toBe(true);
        expect(event.get('is_new_visitor')).toBeFalsy();
    });
});

describe('the two session-start flags have different lifetimes', () => {

    /*
     * is_new_session_start  this REQUEST created the session. Exactly one
     *                       beacon carries it -- what a server deciding
     *                       create-vs-update needs.
     * is_new_session        this event happened on the page the session started
     *                       on. Every beacon from that page carries it -- what
     *                       the is_entry_page dimension needs.
     *
     * One flag was doing both jobs while being read as the first, which is why a
     * second trackPageView() on the same page re-entered logSession() for a
     * session that already existed.
     */

    test('only the first event carries is_new_session_start', () => {
        const t = newTracker();
        const beacons = [];
        t.logEvent = (p) => beacons.push({ ...p });

        t.trackPageView(location.href);
        const later = t.makeEvent();
        later.setEventType('track.action');
        t.trackEvent(later);

        expect(beacons[0].is_new_session_start).toBe(true);
        expect(beacons[1].is_new_session_start).toBeFalsy();
    });

    test('...while every event from that page carries is_new_session', () => {
        const t = newTracker();
        const beacons = [];
        t.logEvent = (p) => beacons.push({ ...p });

        t.trackPageView(location.href);
        const later = t.makeEvent();
        later.setEventType('track.action');
        t.trackEvent(later);

        expect(beacons[0].is_new_session).toBe(true);
        expect(beacons[1].is_new_session).toBe(true);
    });

    test('a second pageview on the same page does not re-declare the start', () => {
        // The SPA case, and the reason the two are separate. Both pageviews are
        // on the page the session started on, so both are entry-page events --
        // but only the first created the session.
        const t = newTracker();
        const beacons = [];
        t.logEvent = (p) => beacons.push({ ...p });

        t.trackPageView('https://example.com/one');
        t.trackPageView('https://example.com/two');

        expect(beacons[0].is_new_session_start).toBe(true);
        expect(beacons[1].is_new_session_start).toBeFalsy();
    });

    test('an active session declares neither', () => {
        // Driven through setSessionId rather than trackPageView: the full
        // pipeline runs setVisitorId, whose clearState('v') refreshes the cookie
        // cache and discards a cache-level seed. See seedPersistedSession().
        const t = newTracker();
        seedPersistedSession({ sid: 'live', last_req: NOW - 60 });

        t.setSessionId(eventAt(NOW), null);

        const event = new OwaEvent();
        t.addGlobalPropertiesToEvent(event);

        expect(event.get('is_new_session_start')).toBeFalsy();
        expect(event.get('is_new_session')).toBeFalsy();
    });
});

describe('last_req tracks activity, not page starts', () => {

    /*
     * The advance runs for every event rather than once per page load. It used
     * to sit inside the stateInit-guarded pipeline, so last_req was the
     * timestamp of the page's FIRST tracked event and never moved -- and the
     * timeout was therefore measured from when the previous page started, not
     * from the last thing the visitor did. isNewSession() already reads as
     * though this were the case: its variable is time_since_lastreq and its
     * comment says "no requests since some time".
     */

    test('a later event on the same page moves it', () => {
        const t = newTracker();
        const beacons = [];
        t.logEvent = (p) => beacons.push({ ...p });

        t.trackPageView(location.href);
        const afterPageview = OWA.getState('s', 'last_req');

        const later = t.makeEvent();
        later.setEventType('track.action');
        later.set('timestamp', afterPageview + 600);
        t.trackEvent(later);

        expect(OWA.getState('s', 'last_req')).toBe(afterPageview + 600);
    });

    test('so a visitor active on one page does not time out from its start', () => {
        // 25 minutes of activity on a page, then a navigation 10 minutes later.
        // Measured from page start that is 35 minutes and a new session;
        // measured from last activity it is 10 and the session continues.
        const t = newTracker();
        t.logEvent = () => {};
        seedPersistedSession({ sid: 'live', last_req: NOW - (35 * 60) });

        const active = t.makeEvent();
        active.setEventType('track.action');
        active.set('timestamp', NOW - (10 * 60));
        t.trackEvent(active);

        expect(OWA.getState('s', 'last_req')).toBe(NOW - (10 * 60));
    });

    test('the reported prior value is not moved by later events', () => {
        // What events REPORT stays the request before this page load; only the
        // store's own last_req tracks activity.
        const t = newTracker();
        const beacons = [];
        t.logEvent = (p) => beacons.push({ ...p });
        seedPersistedSession({ sid: 'live', last_req: NOW - 60 });

        t.trackPageView(location.href);
        const reported = beacons[0].last_req;

        const later = t.makeEvent();
        later.setEventType('track.action');
        later.set('timestamp', NOW + 600);
        t.trackEvent(later);

        expect(beacons[1].last_req).toBe(reported);
    });
});

describe('setNumberPriorSessions', () => {

    test('increments and persists nps on a new session', () => {
        const t = newTracker();
        OWA.setState('v', 'nps', '2', true);
        t.isNewSessionFlag = true;

        t.setNumberPriorSessions(eventAt(NOW), null);

        expect(OWA.getState('v', 'nps')).toBe(3);
        expect(OWA.getState('v', 'nps')).toBe(3);
    });

    test('leaves nps untouched during an active (not new) session', () => {
        const t = newTracker();
        OWA.setState('v', 'nps', '4', true);
        t.isNewSessionFlag = false;

        t.setNumberPriorSessions(eventAt(NOW), null);

        // Not a new session: the stored value rides along unchanged.
        expect(OWA.getState('v', 'nps')).toBe('4');
        expect(OWA.getState('v', 'nps')).toBe('4');
    });
});

describe('setFirstSessionTimestamp', () => {

    test('stamps fsts on the first visit and reports zero days since', () => {
        const t = newTracker();

        t.setFirstSessionTimestamp(eventAt(NOW), null);

        expect(OWA.getState('v', 'fsts')).toBe(NOW);
        expect(OWA.getState('v', 'dsfs')).toBe(0);
        expect(OWA.getState('v', 'fsts')).toBe(NOW);
    });

    test('preserves an existing fsts and computes days since first session', () => {
        const t = newTracker();
        OWA.setState('v', 'fsts', NOW - 3 * DAY, true);

        t.setFirstSessionTimestamp(eventAt(NOW), null);

        // fsts is sticky; only dsfs advances.
        expect(OWA.getState('v', 'fsts')).toBe(NOW - 3 * DAY);
        expect(OWA.getState('v', 'dsfs')).toBe(3);
    });
});

describe('setDaysSinceLastSession', () => {

    test('computes dsps in whole days from last_req on a new session', () => {
        const t = newTracker();
        // The new-session marker is page state now, not a tracker-private
        // property; last_req is still the one per-request fact left on the
        // tracker.
        OWA.setState('d', 'is_new_session', true);
        OWA.setState('s', 'prior_last_req', NOW - 5 * DAY);

        t.setDaysSinceLastSession(eventAt(NOW), null);

        expect(OWA.getState('s', 'dsps')).toBe(5);
    });

    test('carries the stored dsps forward during an active session', () => {
        const t = newTracker();
        OWA.setState('s', 'dsps', 7);
        // is_new_session not set -> falls back to the stored value.
        t.setDaysSinceLastSession(eventAt(NOW), null);

        expect(OWA.getState('s', 'dsps')).toBe(7);
    });
});

describe('setLastRequestTime', () => {

    test('captures the prior last_req under its own key', () => {
        const t = newTracker();
        seedPersistedSession({ last_req: NOW - 1000 });

        t.setLastRequestTime(eventAt(NOW), null);

        // Kept separately because it is what events report, while s.last_req
        // moves on. The advance is not this method's job any more -- it happens
        // for every event, in manageState().
        expect(OWA.getState('s', 'prior_last_req')).toBe(NOW - 1000);
    });

    test('a second tracker on the page does not overwrite the captured value', () => {
        // By the time a second tracker runs, the first has advanced s.last_req
        // to now. Without the guard it would capture THAT as the prior request,
        // and the first tracker's later events would then report it.
        const first = newTracker();
        seedPersistedSession({ last_req: NOW - 1000 });

        first.setLastRequestTime(eventAt(NOW), null);
        const second = newTracker();
        second.setLastRequestTime(eventAt(NOW), null);

        expect(OWA.getState('s', 'prior_last_req')).toBe(NOW - 1000);
    });

    test('sessionization is decided before the advance, so it sees the prior value', () => {
        // The reason the two steps are in this order. Reversed, the store says
        // "now" by the time the decision is made, and the prior value has to be
        // stashed on the tracker for the decision to still see it -- which is
        // what a global event property was doing, and why a second tracker on
        // the page saw a different one.
        const t = newTracker();
        seedPersistedSession({ sid: 'live-session', last_req: NOW - 60 });

        t.setSessionId(eventAt(NOW), null);

        expect(OWA.getState('d', 'is_new_session')).toBeFalsy();
        expect(OWA.getState('s', 'sid')).toBe('live-session');
    });
});

describe('settling the session store on the sessionization decision', () => {

    /*
     * This replaced resetSessionState(). That method cleared the store and then
     * put back the values set during the page load, because by the time it ran
     * both were already mixed in one store with nothing to tell them apart.
     * Keeping them apart until the decision removes the problem instead of
     * compensating for it -- old values are in the cookie, new ones in memory.
     */

    test('a new session discards the persisted store and keeps this page load', () => {
        seedPersistedSession({ sid: 'old-sid', source: 'news', cv1: 'plan=free' });
        const t = newTracker();
        // Set during THIS page load, before the pageview that ends the old one.
        OWA.setState('s', 'cv1', 'plan=pro');
        OWA.setState('s', 'last_req', NOW - 5000);

        t.setSessionId(eventAt(NOW), null);

        // The previous session's values are gone...
        expect(OWA.getState('s', 'source')).toBeFalsy();
        expect(OWA.getState('s', 'prior_session_id')).toBe('old-sid');
        // ...and this page load's survived the boundary it was set for.
        expect(OWA.getState('s', 'cv1')).toBe('plan=pro');
    });

    test('a continuing session merges the persisted store in BEHIND this page load', () => {
        // Merge precedence. Filling gaps is right; overwriting is not, because
        // a value set on this page is newer than one a previous page persisted.
        // Object.assign(memory, cookie) gets this backwards and silently
        // discards the caller's write.
        seedPersistedSession({ sid: 'active-1', cv1: 'plan=free', cv2: 'tier=old' });
        const t = newTracker();
        OWA.setState('s', 'cv1', 'plan=pro');
        OWA.setState('s', 'last_req', NOW);

        t.setSessionId(eventAt(NOW), null);

        expect(OWA.getState('s', 'sid')).toBe('active-1');
        expect(OWA.getState('s', 'cv1')).toBe('plan=pro');   // memory won
        expect(OWA.getState('s', 'cv2')).toBe('tier=old');   // gap filled
    });

    test('the advance survives the settle', () => {
        // The advance happens after the decision and must not be undone by it:
        // a continuing session merges the persisted store in behind memory, and
        // the old last_req in that cookie must not win.
        seedPersistedSession({ sid: 'active-1', last_req: NOW - 100 });
        const t = newTracker();

        t.setSessionId(eventAt(NOW), null);
        t.advanceLastRequestTime(eventAt(NOW));

        expect(OWA.getState('s', 'last_req')).toBe(NOW);
    });
});
