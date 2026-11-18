import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';
import { Util } from '../../modules/Base/src/common/Util.js';

/**
 * Cross-domain (cross-host) visitor state sharing.
 *
 * OWA can carry a visitor's identity across two different domains that both run
 * OWA, so the same person is not counted as a new visitor on each host. The
 * mechanism (see the "Cross Domain Tracking" section of the Javascript Tracker
 * wiki page) is a serialize -> transport -> restore round trip across three
 * tracker methods:
 *
 *   - shareStateByLink(url) / shareStateByPost(form) run on the SOURCE domain.
 *     They serialize the visitor's sharable state stores (visitor 'v', session
 *     's', campaign 'c', and 'b') into a base64 "<ns>state.<payload>" token and
 *     attach it to the outbound URL's anchor (link) or the form action (post).
 *   - checkForLinkedState() runs on the DESTINATION domain. It reads that token
 *     off the URL/anchor, decodes it, and replaces the local state stores so the
 *     visitor arrives already-identified instead of as a brand-new visitor.
 *
 * Two strict-mode cases in checkForLinkedState() are pinned at the bottom of this
 * file: any shared state found, and a sharable store that is empty (the common
 * case: a visitor with no campaign state serializes 'c'/'b' to "").
 *
 * jsdom note: real navigation (assigning document.location.href in
 * shareStateByLink, or form.submit()) is not implemented in jsdom, so we drive
 * the transport we CAN observe -- the token itself -- and the destination side
 * by seeding window.location.hash, which is exactly what a real linked landing
 * page carries.
 */

// A source/destination tracker with cookie plumbing pinned so the round trip is
// deterministic and hashing doesn't gate the restore. cookie_domain_set avoids
// the document.domain auto-detect branch.
function newTracker() {
    return new OWATracker({ cookie_domain_set: true, cookie_domain: '.shared.example' });
}

beforeEach(() => {
    Object.defineProperty(document, 'domain', {
        configurable: true,
        get() { return 'shared.example'; },
    });
    OWA.setSetting('ns', 'owa_');
    OWA.setSetting('cookie_domain', '.shared.example');
    // Turn off domain-hash gating so a store restored under the test domain isn't
    // rejected for a domain-hash mismatch -- we are testing the transport, not
    // the anti-cross-domain-leak hash (which has its own construction).
    OWA.setSetting('hashCookiesToDomain', false);
});

afterEach(() => {
    // Scrub shared state + the URL hash so trackers don't leak identity between
    // tests.
    ['v', 's', 'c', 'b'].forEach(store => OWA.clearState(store));
    window.location.hash = '';
});

describe('cross-domain state: source-side serialization', () => {

    test('createSharedStateValue packs sharable stores into a decodable base64 token', () => {
        const t = newTracker();
        OWA.setState('v', 'visitor_id', 'visitor-abc', true, 'json');
        OWA.setState('s', 'session_id', 'session-xyz', true, 'json');

        const token = t.createSharedStateValue();
        expect(typeof token).toBe('string');
        expect(token.length).toBeGreaterThan(0);

        // The token is url-encoded base64 of "store=value.store=value..."; decode
        // it the way the destination's checkForLinkedState() does and confirm the
        // visitor id we seeded actually rode along.
        const decoded = Util.base64_decode(Util.urldecode(token));
        expect(decoded).toContain('v=');
        expect(decoded).toContain('visitor-abc');
    });

    test('shareStateByPost appends the state token to the form action and submits', () => {
        const t = newTracker();
        OWA.setState('v', 'visitor_id', 'visitor-post', true, 'json');

        // A minimal form stub: real HTMLFormElement.submit() navigates, which
        // jsdom does not implement, so we capture the action mutation + submit.
        let submitted = false;
        const form = {
            action: 'https://other.example/handler',
            submit() { submitted = true; },
        };

        t.shareStateByPost(form);

        // The visitor state was attached to the destination action as the anchor
        // token the receiving OWA will look for, and the form was submitted.
        expect(form.action).toContain('#owa_state.');
        expect(submitted).toBe(true);

        // And the token on the action round-trips back to the seeded visitor id.
        const token = form.action.split('#owa_state.')[1];
        const decoded = Util.base64_decode(Util.urldecode(token));
        expect(decoded).toContain('visitor-post');
    });

    test('shareStateByPost keeps a fragment the form action already has', () => {
        const t = newTracker();
        OWA.setState('v', 'visitor_id', 'visitor-post', true, 'json');
        const form = { action: 'https://other.example/handler#done', submit() {} };

        t.shareStateByPost(form);

        expect(form.action).toMatch(/^https:\/\/other\.example\/handler#done,owa_state\.[^#,]+$/);
    });
});

/*
 * shareStateByLink() to a URL on this document: jsdom performs a fragment-only
 * navigation, so the URL it went to is readable from window.location.
 */
describe('cross-domain state: shareStateByLink', () => {

    function here() {
        return window.location.href.split('#')[0];
    }

    function linkAndArrive(url) {
        const source = newTracker();
        OWA.setState('v', 'visitor_id', 'linked-visitor', true, 'json');
        source.shareStateByLink(url);
        const arrivedAt = window.location.href;

        ['v', 's', 'c', 'b'].forEach(store => OWA.clearState(store));
        const dest = newTracker();
        dest.checkForLinkedState();

        return arrivedAt;
    }

    test('a URL without a fragment gets the state as its fragment', () => {
        const arrivedAt = linkAndArrive(here());

        expect(arrivedAt.startsWith(here() + '#owa_state.')).toBe(true);
        expect(OWA.getState('v').visitor_id).toBe('linked-visitor');
    });

    test('a URL with a fragment keeps it and gets the state after it', () => {
        const arrivedAt = linkAndArrive(here() + '#section-2');

        expect(arrivedAt.startsWith(here() + '#section-2,owa_state.')).toBe(true);
        expect(OWA.getState('v').visitor_id).toBe('linked-visitor');
    });

    test('the page linking from having a fragment does not stop the state being sent', () => {
        window.location.hash = 'on-this-page';

        const arrivedAt = linkAndArrive(here());

        expect(arrivedAt.startsWith(here() + '#owa_state.')).toBe(true);
        expect(OWA.getState('v').visitor_id).toBe('linked-visitor');
    });

    test('a URL ending in an empty fragment gets the state as its fragment', () => {
        const t = newTracker();

        expect(t.addSharedStateToUrl('https://other.example/p#', 'TOKEN'))
            .toBe('https://other.example/p#owa_state.TOKEN');
    });
});

describe('cross-domain state: destination-side restoration', () => {

    test('checkForLinkedState restores a visitor id carried on the landing URL anchor', () => {
        // SOURCE domain builds the token...
        const source = newTracker();
        OWA.setState('v', 'visitor_id', 'carried-visitor', true, 'json');
        OWA.setState('s', 'session_id', 'carried-session', true, 'json');
        const token = source.createSharedStateValue();

        // ...clear local state to simulate arriving fresh on the DESTINATION...
        ['v', 's', 'c', 'b'].forEach(store => OWA.clearState(store));

        // ...the linked landing page carries the token on its anchor...
        window.location.hash = 'owa_state.' + token;

        const dest = newTracker();
        dest.checkForLinkedState();

        // The destination now sees the SOURCE visitor rather than a new one.
        expect(dest.linkedStateSet).toBe(true);
        const v = OWA.getState('v');
        expect(v).toBeTruthy();
        expect(v.visitor_id).toBe('carried-visitor');
        const s = OWA.getState('s');
        expect(s.session_id).toBe('carried-session');
    });

    test('checkForLinkedState is idempotent: a second call does not re-process', () => {
        const source = newTracker();
        OWA.setState('v', 'visitor_id', 'once-visitor', true, 'json');
        const token = source.createSharedStateValue();
        window.location.hash = 'owa_state.' + token;

        const dest = newTracker();
        dest.checkForLinkedState();
        expect(dest.linkedStateSet).toBe(true);

        // Guard flag is set; calling again is a no-op (it must not throw or
        // re-read the anchor). We just assert it stays consistent.
        dest.checkForLinkedState();
        expect(dest.linkedStateSet).toBe(true);
    });

    test('checkForLinkedState is a safe no-op when no linked state is present', () => {
        // No hash, no url param -> nothing to restore, must not throw, and must
        // not fabricate a visitor store.
        window.location.hash = '';
        const dest = newTracker();

        expect(() => dest.checkForLinkedState()).not.toThrow();
        expect(dest.linkedStateSet).toBe(true); // ran, found nothing
        expect(OWA.getState('v')).toBeFalsy();
    });
});

describe('cross-domain state: strict-mode regression guards', () => {

    test('restoring shared state does not throw a ReferenceError (undeclared decodedvalue)', () => {
        // Under the bundle's strict mode, an undeclared assignment in
        // checkForLinkedState would throw the instant any shared state is found.
        // This exercises exactly that path.
        const source = newTracker();
        OWA.setState('v', 'visitor_id', 'regress-visitor', true, 'json');
        const token = source.createSharedStateValue();
        window.location.hash = 'owa_state.' + token;

        const dest = newTracker();
        expect(() => dest.checkForLinkedState()).not.toThrow();
        expect(OWA.getState('v').visitor_id).toBe('regress-visitor');
    });

    test('an empty sharable store in the token does not throw and is skipped', () => {
        // A visitor with no campaign state serializes 'c'/'b' to "", which decodes
        // to a non-object on the destination. Seed ONLY the visitor store so 'c'/'b'
        // ride along empty, and assert the populated store still restores while the
        // empty ones are skipped rather than crashing the whole hand-off.
        const source = newTracker();
        OWA.setState('v', 'visitor_id', 'lonely-visitor', true, 'json');
        const token = source.createSharedStateValue();
        window.location.hash = 'owa_state.' + token;

        const dest = newTracker();
        expect(() => dest.checkForLinkedState()).not.toThrow();
        expect(dest.linkedStateSet).toBe(true);
        expect(OWA.getState('v').visitor_id).toBe('lonely-visitor');
    });
});
