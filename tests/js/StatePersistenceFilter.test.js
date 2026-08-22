jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';
import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { Util } from '../../modules/Base/src/common/Util.js';

/**
 * When the session store reaches the cookie.
 *
 * THE ORIGINAL PROBLEM
 * The tracker used to write s.sid and s.last_req the moment they were derived,
 * before the request carrying them was transmitted. When that first beacon died
 * (typically cancelled by the browser when the visitor clicked through while the
 * 1x1 pixel was still in flight) the cookie asserted a session the server had
 * never been told about. Every later page then read that sid, sent
 * is_new_session UNSET, and the server took sessionHandlers::logSessionUpdate(),
 * which correctly aborts -- on a multi-server setup an update can legitimately
 * arrive before its create -- and requeued the event. Nothing reconciled it, so
 * one lost first beacon stranded every subsequent hit in that session.
 *
 * The first fix withheld those two KEYS from the cookie until a send was
 * accepted, and let every other key in the store persist immediately.
 *
 * WHAT CHANGED, AND WHY
 * The session store is now withheld WHOLE until the session is known to be
 * worth persisting, on the 'persistSession' action. Two reasons:
 *
 *   1. Holding a value back is what makes it distinguishable. A session-scoped
 *      value in memory was set by THIS page load; one in the cookie was left by
 *      a previous one. Writing memory through immediately destroyed that
 *      distinction, and a new session could then neither keep the new values nor
 *      discard the old ones without a label to tell them apart. Nothing durable
 *      could carry that label. See CustomVarStorage.test.js.
 *
 *   2. It makes the store commit atomically. Persisting referer or a custom var
 *      while withholding sid wrote a half-session to disk: state describing a
 *      session whose identity was not recorded. Whatever read it next attached
 *      those values to a different session.
 *
 * This reverses a rationale stated in this file's previous header -- that custom
 * vars are "publisher intent that must not hinge on transport". They do hinge on
 * it now, and that is the point: a session-scoped value cannot outlive the
 * persistence of the session it is scoped to. If the sid never lands, there is
 * no session for the variable to belong to, and persisting it alone would attach
 * it to whatever session came next.
 *
 * These assert against the value handed to Util.setCookie rather than
 * document.cookie: jsdom refuses cookies whose domain does not match the test
 * document URL, so reading the jar back would make every "is withheld" assertion
 * pass trivially against an empty string.
 */

let writes;

/** The value most recently serialized to a store's cookie, or null. */
function lastWrite(store) {
    const calls = writes.mock.calls.filter((c) => c[0] === 'owa_' + store);
    return calls.length ? String(calls[calls.length - 1][1]) : null;
}

const NOW = 1700000000;

/** Put the state manager back to the state a fresh page load starts in. */
function coldPage() {
    OWA.initializeStateManager();
    // A store's hydration and persistence behaviour comes from its
    // REGISTRATION, so these tests have to register before they mean anything.
    // Constructing a tracker is how that happens in production; restating the
    // store table here instead would just let it drift out of step.
    new OWATracker({ cookie_domain_set: true });
    OWA.state.stores = {};
    OWA.state.storeFormats = {};
    OWA.state.hydrated = {};
    OWA.state.sessionPersistenceReady = false;
}

beforeEach(() => {
    OWA.setSetting('ns', 'owa_');
    OWA.setSetting('cookie_domain', 'persist.example');
    OWA.setSetting('hashCookiesToDomain', false);
    ['v', 's', 'c', 'b', 'd'].forEach((store) => OWA.clearState(store));
    coldPage();
    writes = jest.spyOn(Util, 'setCookie');
    writes.mockClear();
});

afterEach(() => {
    writes.mockRestore();
    coldPage();
    ['v', 's', 'c', 'b', 'd'].forEach((store) => OWA.clearState(store));
});

describe('store behaviour is declared at registration', () => {

    /*
     * It used to be hardcoded in StateManager's constructor as maps keyed by
     * one-letter store name -- so a store's identity was declared in the
     * tracker while its behaviour lived in another file, and registering a new
     * store gave you no way to say how it should behave.
     */

    test('the session store defers hydration and waits for the session to persist', () => {
        expect(OWA.state.behaviourOf('s')).toMatchObject({
            hydrate: 'deferred',
            persist: 'session',
        });
    });

    test('the page store is memory only', () => {
        expect(OWA.state.behaviourOf('d').persist).toBe('never');
    });

    test('the legacy custom variable store declares what it collapses into', () => {
        expect(OWA.state.behaviourOf('b').collapseInto).toBe('s');
    });

    test('the visitor and campaign stores take the defaults', () => {
        ['v', 'c'].forEach((store) => {
            expect(OWA.state.behaviourOf(store)).toMatchObject({
                hydrate: 'eager',
                persist: 'immediate',
                collapseInto: '',
            });
        });
    });

    test('an unregistered store takes the defaults rather than throwing', () => {
        // Something written to before the tracker registered it behaves the way
        // every store did before any of this existed.
        expect(OWA.state.behaviourOf('zz')).toMatchObject({
            hydrate: 'eager',
            persist: 'immediate',
        });
    });
});

describe('the session store is withheld until the session is persistable', () => {

    test('sid is not serialized before persistSession', () => {
        OWA.setState('s', 'sid', 'session-abc', true);

        expect(lastWrite('s')).toBeNull();
    });

    test('nor is anything else in the store', () => {
        // The previous design let these through. They are session state, and
        // writing them without the sid records a session that has no identity.
        OWA.setState('s', 'referer', 'https://newsletter.example/issue-7');
        OWA.setState('s', 'campaign', 'spring');
        OWA.setState('s', 'cv1', 'plan=pro');

        expect(lastWrite('s')).toBeNull();
    });

    test('...but every one of them is readable from memory', () => {
        // Withholding is about the cookie only. Every event on this page must
        // read the same session, and cross-domain state sharing serializes the
        // store out of memory.
        OWA.setState('s', 'sid', 'session-abc', true);
        OWA.setState('s', 'cv1', 'plan=pro');

        expect(OWA.getState('s', 'sid')).toBe('session-abc');
        expect(OWA.getState('s', 'cv1')).toBe('plan=pro');
        expect(OWA.getState('s')).toHaveProperty('sid', 'session-abc');
    });

    test('the visitor and campaign stores are unaffected', () => {
        // Only 's' is session-bound. A visitor outlives their sessions, so
        // nothing about the session decision governs when 'v' is written.
        OWA.setState('v', 'vid', 'visitor-xyz', true);
        OWA.setState('c', 'cn', 'spring');

        expect(lastWrite('v')).toContain('visitor-xyz');
        expect(lastWrite('c')).toContain('spring');
    });

    test('the page store is never written at all', () => {
        // 'd' is memory-only for the life of the page.
        OWA.setState('d', 'cv1', 'plan=pro');

        expect(lastWrite('d')).toBeNull();
        expect(OWA.getState('d', 'cv1')).toBe('plan=pro');
    });
});

describe('persistSession flushes the store and keeps it writing', () => {

    test('the whole store lands at once', () => {
        OWA.setState('s', 'sid', 'session-abc', true);
        OWA.setState('s', 'last_req', NOW, true);
        OWA.setState('s', 'cv1', 'plan=pro');
        expect(lastWrite('s')).toBeNull();

        OWA.doAction('persistSession');

        const written = lastWrite('s');
        expect(written).toContain('session-abc');
        expect(written).toContain(String(NOW));
        expect(written).toContain('plan=pro');
    });

    test('later writes persist as they are made', () => {
        OWA.setState('s', 'sid', 'session-abc', true);
        OWA.doAction('persistSession');

        OWA.setState('s', 'referer', 'https://newsletter.example/issue-7');

        expect(lastWrite('s')).toContain('newsletter.example');
    });

    test('clearing a key after the flush rewrites the store without it', () => {
        // clear(store, key) rebuilds the store minus one key via replaceStore(),
        // a second path to the cookie.
        OWA.setState('s', 'sid', 'session-abc', true);
        OWA.setState('s', 'dsps', 3);
        OWA.doAction('persistSession');

        OWA.clearState('s', 'dsps');

        const written = lastWrite('s');
        expect(written).toContain('session-abc');
        expect(written).not.toContain('dsps');
    });

    test('a store never touched on this page is not written', () => {
        OWA.doAction('persistSession');

        expect(lastWrite('s')).toBeNull();
    });
});

describe('a send that is never accepted leaves the cookie alone', () => {

    test('nothing is written when persistSession never fires', () => {
        // The image.onerror path, and the torn-down-page path: the beacon
        // failed or was never acknowledged, so the session was not announced to
        // the server and must not be asserted by the cookie. The next page finds
        // no sid, treats itself as a new session, and the server creates it
        // properly -- one lost hit rather than a stranded session.
        //
        // Note there is nothing to call here. Failure is the absence of the
        // acceptance, not an event of its own, which is why the withheld-by-
        // default arrangement replaced the raise-a-flag-then-lower-it one.
        OWA.setState('s', 'sid', 'session-abc', true);
        OWA.setState('s', 'last_req', NOW, true);

        expect(lastWrite('s')).toBeNull();
    });
});
