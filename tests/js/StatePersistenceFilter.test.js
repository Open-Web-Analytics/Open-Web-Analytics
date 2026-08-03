import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';
import { Util } from '../../modules/Base/src/common/Util.js';

/**
 * Deferred persistence of session identity.
 *
 * The tracker used to write s.sid and s.last_req to the cookie the moment they
 * were derived -- before the request carrying them was transmitted. When that
 * first beacon died (typically cancelled by the browser when the visitor clicked
 * through while the 1x1 pixel was still in flight), the cookie asserted a session
 * the server had never been told about. Every later page then read that sid, sent
 * is_new_session UNSET, and the server took sessionHandlers::logSessionUpdate(),
 * which correctly aborts -- on a multi-server setup an update can legitimately
 * arrive before its create -- and requeued the event. Nothing ever reconciled it,
 * so one lost first beacon stranded every subsequent hit in that session.
 *
 * The fix withholds ONLY those two keys from the cookie until a send is accepted:
 *
 *   - memory (this.stores) always holds them, so every event on the page reads
 *     the same session and cross-domain state sharing still carries the sid;
 *   - the filter lives in persist(), not set(), because the cookie is per-STORE.
 *     Writing any other key in 's' (referer, campaign keys, dsps) re-serializes
 *     the whole store, and would otherwise smuggle an undelivered sid to disk;
 *   - everything else persists immediately. referer and campaign keys are facts
 *     observable ONLY on the landing hit and are unrecoverable if dropped, and
 *     custom vars are publisher intent that must not hinge on transport.
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

beforeEach(() => {
    OWA.setSetting('ns', 'owa_');
    OWA.setSetting('cookie_domain', 'persist.example');
    OWA.setSetting('hashCookiesToDomain', false);
    ['v', 's', 'c', 'b'].forEach((store) => OWA.clearState(store));
    OWA.abandonDeferredStatePersistence();
    writes = jest.spyOn(Util, 'setCookie');
    writes.mockClear();
});

afterEach(() => {
    writes.mockRestore();
    OWA.abandonDeferredStatePersistence();
    ['v', 's', 'c', 'b'].forEach((store) => OWA.clearState(store));
});

describe('deferred keys are withheld from the cookie but live in memory', () => {

    test('sid and last_req are not serialized while deferring', () => {
        OWA.beginDeferredStatePersistence();

        OWA.setState('s', 'sid', 'session-abc', true);
        OWA.setState('s', 'last_req', NOW, true);

        // The store IS written (it exists) -- it just must not carry these keys.
        expect(lastWrite('s')).not.toBeNull();
        expect(lastWrite('s')).not.toContain('session-abc');
        expect(lastWrite('s')).not.toContain('last_req');
    });

    test('...but ARE readable from memory, so same-page events share the session', () => {
        OWA.beginDeferredStatePersistence();

        OWA.setState('s', 'sid', 'session-abc', true);

        expect(OWA.getState('s', 'sid')).toBe('session-abc');
    });

    test('a whole-store read still sees them (cross-domain state sharing)', () => {
        OWA.beginDeferredStatePersistence();

        OWA.setState('s', 'sid', 'session-abc', true);

        expect(OWA.getState('s')).toHaveProperty('sid', 'session-abc');
    });
});

describe('non-deferred keys in the same store still persist immediately', () => {

    test('referer is serialized even while deferring', () => {
        OWA.beginDeferredStatePersistence();

        OWA.setState('s', 'referer', 'https://newsletter.example/issue-7');

        expect(lastWrite('s')).toContain('newsletter.example');
    });

    test('a referer write does not leak the withheld sid to disk', () => {
        OWA.beginDeferredStatePersistence();

        OWA.setState('s', 'sid', 'session-abc', true);
        // Writing ANY key re-serializes the whole store. Without the filter in
        // persist(), this write alone would commit the sid.
        OWA.setState('s', 'referer', 'https://newsletter.example/issue-7');

        expect(lastWrite('s')).toContain('newsletter.example');
        expect(lastWrite('s')).not.toContain('session-abc');
    });

    test('campaign keys are serialized even while deferring', () => {
        OWA.beginDeferredStatePersistence();

        OWA.setState('s', 'sid', 'session-abc', true);
        OWA.setState('s', 'campaign', 'spring');

        expect(lastWrite('s')).toContain('spring');
        expect(lastWrite('s')).not.toContain('session-abc');
    });

    test('other stores are unaffected by the s-store filter', () => {
        OWA.beginDeferredStatePersistence();

        OWA.setState('v', 'vid', 'visitor-xyz', true);
        OWA.setState('b', 'cv1', 'plan=pro');

        expect(lastWrite('v')).toContain('visitor-xyz');
        expect(lastWrite('b')).toContain('plan=pro');
    });
});

describe('commit and abandon', () => {

    test('commit serializes the withheld keys', () => {
        OWA.beginDeferredStatePersistence();
        OWA.setState('s', 'sid', 'session-abc', true);
        expect(lastWrite('s')).not.toContain('session-abc');

        OWA.commitDeferredStatePersistence();

        expect(lastWrite('s')).toContain('session-abc');
    });

    test('abandon leaves them unwritten', () => {
        OWA.beginDeferredStatePersistence();
        OWA.setState('s', 'sid', 'session-abc', true);

        OWA.abandonDeferredStatePersistence();

        expect(lastWrite('s')).not.toContain('session-abc');
    });

    test('commit is a no-op once deferral has already been resolved', () => {
        // A second event on the same page must not commit a session whose own
        // send failed: abandon clears the flag, so the click's later successful
        // beacon finds nothing to commit.
        OWA.beginDeferredStatePersistence();
        OWA.setState('s', 'sid', 'session-abc', true);
        OWA.abandonDeferredStatePersistence();
        writes.mockClear();

        OWA.commitDeferredStatePersistence();

        expect(lastWrite('s')).toBeNull();
    });

    test('once resolved, later writes persist normally', () => {
        OWA.beginDeferredStatePersistence();
        OWA.commitDeferredStatePersistence();

        OWA.setState('s', 'sid', 'session-later', true);

        expect(lastWrite('s')).toContain('session-later');
    });
});

describe('clearing a single key honours the filter', () => {

    test('clear(s, dsps) does not write the withheld sid to disk', () => {
        OWA.beginDeferredStatePersistence();
        OWA.setState('s', 'sid', 'session-abc', true);
        OWA.setState('s', 'dsps', 3);

        // clear(store, key) rebuilds the store minus one key via replaceStore(),
        // a second path to the cookie that needs the same filter.
        OWA.clearState('s', 'dsps');

        expect(lastWrite('s')).not.toContain('session-abc');
    });
});
