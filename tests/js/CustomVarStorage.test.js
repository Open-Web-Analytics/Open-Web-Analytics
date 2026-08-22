jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';
import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { Util } from '../../modules/Base/src/common/Util.js';

/**
 * Custom variables live in the session store, and every store is JSON.
 *
 * TWO COOKIES FOR ONE CONCEPT
 * Session-scoped custom variables were kept in a store called 'b', sitting
 * beside 's', which IS the session store. So a variable scoped to the session
 * did not share the lifetime of the session it was scoped to, and every request
 * carried an extra cookie to say so.
 *
 * ONE ENCODING
 * 'v' and 's' were serialized as key=>value|||key=>value with no escaping of
 * either separator, so a value containing '=>' or '|||' silently corrupted the
 * whole store. All four stores are JSON now.
 *
 * Both changes are safe under an existing installation because the loader
 * SNIFFS what it reads -- a leading '{' means JSON, anything else means assoc --
 * so an old cookie is parsed in its own format and rewritten in the new one.
 * These tests assert that compatibility explicitly, since it is the whole
 * reason the change can ship without a migration.
 *
 * MEMORY IS NOT THE COOKIE
 * A session-scoped variable is set into MEMORY. It reaches the cookie only once
 * the session has been settled and a request carrying it accepted. That is what
 * makes it distinguishable from a value a previous session left behind: old
 * values are in the cookie, new ones are in memory, and the sessionization
 * decision keeps whichever belongs to the session now starting. Nothing has to
 * label them, which matters because the label could not itself be persisted.
 *
 * Tests here therefore simulate a page boundary honestly -- sendAccepted() for
 * the acceptance the stubbed transport cannot give, then nextPageLoad() to
 * throw memory away and keep the cookie. Without that, a second tracker reads
 * the first one's leftover memory and every assertion passes for the wrong
 * reason.
 */
describe('custom variable storage', () => {

    let tracker;

    beforeEach(() => {
        // Erase through the API, not by hand. Util.setCookie writes with a
        // domain attribute, and a hand-rolled expiry that omits the domain does
        // not remove such a cookie -- it just shadows it at a narrower scope.
        // That used to be harmless because memory was authoritative; now the
        // cookie is where a PREVIOUS session lives, so a survivor makes a
        // new-session test observe a live session instead.
        OWA.initializeStateManager();
        ['v', 's', 'c', 'b', 'd'].forEach((store) => OWA.clearState(store));
        document.cookie.split(';').forEach((c) => {
            const name = c.split('=')[0].trim();
            if (name) { document.cookie = `${name}=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/`; }
        });
        OWA.state.stores = {};
        OWA.state.storeFormats = {};
        // cookie_domain_set, as the other tracker suites do: without it
        // trackPageView() tries to derive a cookie domain and jsdom has none.
        OWA.state.hydrated = {};
        OWA.state.persistenceReleased = {};
        tracker = new OWATracker({ cookie_domain_set: true });
        tracker.setSiteId('cv-storage-test');
    });

    /** The page went away. Cookies survive it; memory does not. */
    function nextPageLoad() {
        OWA.state.stores = {};
        OWA.state.storeFormats = {};
        OWA.state.hydrated = {};
        OWA.state.persistenceReleased = {};
        // The state manager reads through a cookie CACHE. Clearing the jar
        // above without refreshing it leaves the previous test's session
        // readable, which now matters: the cookie is where a PREVIOUS session
        // lives, so a stale one makes a new-session test see a live session.
        OWA.state.cookies = Util.readAllCookies();
        OWA.state.cookies = Util.readAllCookies();
    }

    /** Track a pageview through the real pipeline with the transport stubbed. */
    function pageView(t, beacons) {
        t.logEvent = (p) => beacons.push({ ...p });
        t.trackPageView(location.href);
        // The transport is stubbed, so the acceptance signal it would have
        // produced has to come from here. Without it the session is never
        // persisted -- correctly, since nothing was ever delivered.
        t.sendAccepted();
    }

    test('a session-scoped variable is stored in the session store', () => {
        tracker.setCustomVar(1, 'plan', 'pro', 'session');

        expect(OWA.getState('s', 'cv1')).toBe('plan=pro');
        expect(OWA.getState('b', 'cv1')).toBeFalsy();
    });

    /**
     * The bug that made this design necessary, pinned so it cannot come back.
     *
     * The usual call order is setCustomVar() then trackPageView(). If that
     * pageview starts a NEW session, the session store has to be cleared of the
     * previous session's values -- and a value written at setCustomVar() time
     * was indistinguishable from those, so it was wiped by the very session it
     * was set for, and every later pageview in that session lost it.
     *
     * Keeping it in MEMORY until the session is settled is what fixes it: the
     * cookie holds what a previous session left, memory holds what this page
     * load set, and a new session discards the one and keeps the other.
     */
    test('a variable set before the first pageview survives into that session', () => {
        // Slot within maxCustomVars (5): only those slots are rehydrated onto a
        // later event, so a higher slot would appear to work on the first
        // beacon and silently vanish afterwards.
        tracker.setCustomVar(2, 'plan', 'pro', 'session');

        const beacons = [];
        pageView(tracker, beacons);

        expect(beacons[0].is_new_session).toBe(true);
        expect(beacons[0].cv2).toBe('plan=pro');

        // a second pageview in the SAME session must still carry it
        nextPageLoad();
        const next = new OWATracker({ cookie_domain_set: true });
        next.setSiteId(tracker.getSiteId());
        pageView(next, beacons);

        expect(beacons[1].is_new_session).toBeFalsy();
        expect(beacons[1].cv2).toBe('plan=pro');
    });

    test('a visitor-scoped variable still goes to the visitor store', () => {
        tracker.setCustomVar(2, 'tier', 'gold', 'visitor');

        expect(OWA.getState('v', 'cv2')).toBe('tier=gold');
        expect(OWA.getState('s', 'cv2')).toBeFalsy();
    });

    test('promoting a variable from session to visitor leaves no session copy', () => {
        tracker.setCustomVar(3, 'x', '1', 'session');
        tracker.setCustomVar(3, 'x', '2', 'visitor');

        expect(OWA.getState('v', 'cv3')).toBe('x=2');
        expect(OWA.getState('s', 'cv3')).toBeFalsy();
        expect(tracker.getCustomVar(3)).toBe('x=2');
    });

    /**
     * The compatibility case: a visitor who was mid-session when this shipped
     * still has values in the old store, and must keep seeing them.
     */
    test('a value left in the old store is still readable', () => {
        OWA.setState('b', 'cv4', 'legacy=value');
        tracker.deleteGlobalEventProperty('cv4');

        expect(tracker.getCustomVar(4)).toBe('legacy=value');
    });

    test('a new write wins over a value left in the old store', () => {
        OWA.setState('b', 'cv5', 'stale=old');
        tracker.setCustomVar(5, 'fresh', 'new', 'session');
        tracker.deleteGlobalEventProperty('cv5');

        expect(tracker.getCustomVar(5)).toBe('fresh=new');
    });

    test('deleting clears the current, legacy and visitor stores', () => {
        OWA.setState('b', 'cv6', 'legacy=value');
        tracker.setCustomVar(6, 'a', 'b', 'visitor');
        tracker.deleteCustomVar(6);

        expect(OWA.getState('s', 'cv6')).toBeFalsy();
        expect(OWA.getState('b', 'cv6')).toBeFalsy();
        expect(OWA.getState('v', 'cv6')).toBeFalsy();
        expect(tracker.getCustomVar(6)).toBeFalsy();
    });

    describe('storage format', () => {

        test('every registered store serializes as JSON', () => {
            ['v', 's', 'c', 'b', 'd'].forEach((store) => {
                expect(OWA.state.getFormat(store)).toBe('json');
            });
        });

        /**
         * The reason the old format had to go: it separated keys with '=>' and
         * '|||' and escaped neither.
         */
        test('a value containing the old separators survives a round trip', () => {
            const nasty = 'a=>b|||c';

            OWA.setState('s', 'cv7', nasty);

            expect(OWA.getState('s', 'cv7')).toBe(nasty);
        });

        /**
         * The migration seam. An existing visitor's cookie is in the old
         * format, and must still be read -- otherwise this change would log
         * everyone out of their own state.
         */
        test('a cookie written in the old format is still parsed', () => {
            expect(Util.getCookieValueFormat('sid=>abc123|||last_req=>999')).toBe('assoc');
            expect(Util.getCookieValueFormat('{"sid":"abc123"}')).toBe('json');
        });

        /**
         * The migration, end to end and mid-session.
         *
         * A visitor who was part-way through a session when this shipped has an
         * assoc-format 's' cookie. Their session must continue -- not restart --
         * their custom variables must come across, and the cookie must be
         * rewritten in the new format without anything being lost on the way.
         *
         * This is the case the format sniff exists for, and it is worth driving
         * through the real pipeline rather than asserting on the sniff alone:
         * the sniff is one line, but the value has to survive being decoded as
         * assoc, merged into memory by hydrate(), and re-serialized as JSON,
         * and it is the round trip that can drop something.
         */
        test('an assoc-format session cookie is inherited mid-session and rewritten as JSON', () => {
            const now = Util.getCurrentUnixTimestamp();
            const parts = [];
            if (OWA.getSetting('hashCookiesToDomain')) {
                parts.push('cdh=>' + Util.getCookieDomainHash(OWA.getSetting('cookie_domain')));
            }
            parts.push('sid=>legacy-session');
            parts.push('last_req=>' + now);
            parts.push('cv1=>plan=pro');

            Util.setCookie(
                OWA.getSetting('ns') + 's',
                parts.join('|||'),
                1,
                '/',
                OWA.getSetting('cookie_domain')
            );
            nextPageLoad();

            // the seeded cookie really is in the old format
            expect(Util.getCookieValueFormat(unescape(Util.readCookie('owa_s')))).toBe('assoc');

            const beacons = [];
            const t = new OWATracker({ cookie_domain_set: true });
            t.setSiteId('cv-storage-test');
            pageView(t, beacons);

            // the session continued rather than restarting...
            expect(beacons[0].is_new_session).toBeFalsy();
            expect(beacons[0].session_id).toBe('legacy-session');
            // ...the variable set in the previous format came across...
            expect(beacons[0].cv1).toBe('plan=pro');
            expect(OWA.getState('s', 'cv1')).toBe('plan=pro');

            // ...and the cookie is now JSON.
            expect(Util.getCookieValueFormat(unescape(Util.readCookie('owa_s')))).toBe('json');
        });

        test('a variable set on the page that inherits an assoc cookie is not lost by the rewrite', () => {
            // The two halves meeting: an old-format store being merged in
            // behind a value this page load set. Memory must still win.
            const now = Util.getCurrentUnixTimestamp();
            const parts = [];
            if (OWA.getSetting('hashCookiesToDomain')) {
                parts.push('cdh=>' + Util.getCookieDomainHash(OWA.getSetting('cookie_domain')));
            }
            parts.push('sid=>legacy-session');
            parts.push('last_req=>' + now);
            parts.push('cv1=>plan=free');
            parts.push('cv2=>tier=old');

            Util.setCookie(
                OWA.getSetting('ns') + 's',
                parts.join('|||'),
                1,
                '/',
                OWA.getSetting('cookie_domain')
            );
            nextPageLoad();

            const beacons = [];
            const t = new OWATracker({ cookie_domain_set: true });
            t.setSiteId('cv-storage-test');
            t.setCustomVar(1, 'plan', 'pro', 'session');
            pageView(t, beacons);

            expect(beacons[0].cv1).toBe('plan=pro');    // this page load won
            expect(beacons[0].cv2).toBe('tier=old');    // inherited from assoc
            expect(Util.getCookieValueFormat(unescape(Util.readCookie('owa_s')))).toBe('json');
        });
    });

    describe('collapsing the legacy custom variable cookie', () => {

        /**
         * 'b' is folded into 's' the first time a visitor holding one is seen,
         * and the cookie is dropped. Reading it as a fallback was never enough:
         * because nothing cleared it at a session boundary, a variable left
         * there by a session that had ended was still found by that read and put
         * back on the wire -- the same leak the move to 's' was meant to close,
         * surviving in the store being moved away from.
         */
        function seedLegacyStore(value, sessionCookie, slot) {
            const cdh = OWA.getSetting('hashCookiesToDomain')
                ? Util.getCookieDomainHash(OWA.getSetting('cookie_domain'))
                : null;
            const ns = OWA.getSetting('ns');
            const domain = OWA.getSetting('cookie_domain');

            const b = {};
            b[slot || 'cv1'] = value;
            if (cdh) { b.cdh = cdh; }
            Util.setCookie(ns + 'b', JSON.stringify(b), '', '/', domain);

            if (sessionCookie) {
                const sess = Object.assign({ sid: 'legacy-session' }, sessionCookie);
                if (cdh) { sess.cdh = cdh; }
                Util.setCookie(ns + 's', JSON.stringify(sess), 1, '/', domain);
            }
            nextPageLoad();
        }

        test('it runs when the cookie domain is established, before anything is tracked', () => {
            // The migration depends on nothing except knowing the cookie domain,
            // so it does not wait for a pageview. A page that never tracks one
            // still stops sending the extra cookie.
            seedLegacyStore('plan=pro', { last_req: Util.getCurrentUnixTimestamp() });

            const t = new OWATracker({ cookie_domain_set: true });
            t.setSiteId('cv-storage-test');

            expect(Util.readCookie('owa_b')).toBeFalsy();
            expect(unescape(Util.readCookie('owa_s'))).toContain('plan=pro');
        });

        test('the value moves cookie-to-cookie, not into memory', () => {
            // Merging into memory would be the subtle version of the bug this
            // design exists to prevent: memory is what marks a value as set by
            // THIS page load, so a legacy value landing there would survive a
            // new session that ought to discard it. It must arrive as what it
            // is -- something a previous page persisted.
            seedLegacyStore('plan=pro', { last_req: Util.getCurrentUnixTimestamp() });

            const t = new OWATracker({ cookie_domain_set: true });
            t.setSiteId('cv-storage-test');

            expect(OWA.state.isPresent('s')).toBeFalsy();
            expect(unescape(Util.readCookie('owa_s'))).toContain('plan=pro');
        });

        test('a live session inherits the legacy value and the old cookie is dropped', () => {
            seedLegacyStore('plan=pro', { last_req: Util.getCurrentUnixTimestamp() });

            const beacons = [];
            const t = new OWATracker({ cookie_domain_set: true });
            t.setSiteId('cv-storage-test');
            pageView(t, beacons);

            expect(beacons[0].is_new_session).toBeFalsy();
            expect(beacons[0].cv1).toBe('plan=pro');
            // it lives in the session store now...
            expect(OWA.getState('s', 'cv1')).toBe('plan=pro');
            // ...and the cookie it came from is gone, so it stops being sent
            expect(Util.readCookie('owa_b')).toBeFalsy();
        });

        test('a NEW session does not inherit it -- the leak this closes', () => {
            // last_req far in the past: the session that owned this value ended.
            seedLegacyStore('stale=lastweek', { last_req: 1000 });

            const beacons = [];
            const t = new OWATracker({ cookie_domain_set: true });
            t.setSiteId('cv-storage-test');
            pageView(t, beacons);

            expect(beacons[0].is_new_session).toBe(true);
            expect(beacons[0].cv1).toBeFalsy();
            expect(t.getCustomVar(1)).toBeFalsy();
            expect(Util.readCookie('owa_b')).toBeFalsy();
        });

        /**
         * Writing the target and erasing the legacy store are two separate
         * cookie operations, so a run can end between them. The next page load
         * then finds the values already in 's' with 'b' still sitting there.
         *
         * The job is to finish, not to start again.
         */
        test('a migration that failed after writing is finished, not repeated', () => {
            seedLegacyStore('plan=free', {
                last_req: Util.getCurrentUnixTimestamp(),
                cv1: 'plan=pro',
            });

            const t = new OWATracker({ cookie_domain_set: true });
            t.setSiteId('cv-storage-test');

            // the erase that was owed
            expect(Util.readCookie('owa_b')).toBeFalsy();
            // and the target is left exactly as the first run wrote it
            const written = unescape(Util.readCookie('owa_s'));
            expect(written).toContain('plan=pro');
            expect(written).not.toContain('plan=free');
        });

        test('a slot removed since the failed migration is not resurrected', () => {
            // 's' carries cv2, so the migration is detectably done -- but cv1
            // is no longer there. Merging again would put it back from 'b',
            // which is why the check is "has this store been migrated into"
            // and not "is this particular slot already present".
            seedLegacyStore('gone=value', {
                last_req: Util.getCurrentUnixTimestamp(),
                cv2: 'kept=value',
            }, 'cv1');

            const t = new OWATracker({ cookie_domain_set: true });
            t.setSiteId('cv-storage-test');

            const written = unescape(Util.readCookie('owa_s'));
            expect(written).toContain('kept=value');
            expect(written).not.toContain('gone=value');
            expect(Util.readCookie('owa_b')).toBeFalsy();
        });

        test('a value set on this page load beats the legacy one', () => {
            seedLegacyStore('plan=free', { last_req: Util.getCurrentUnixTimestamp() });

            const beacons = [];
            const t = new OWATracker({ cookie_domain_set: true });
            t.setSiteId('cv-storage-test');
            t.setCustomVar(1, 'plan', 'pro', 'session');
            pageView(t, beacons);

            expect(beacons[0].cv1).toBe('plan=pro');
        });

        test('the collapse survives to the next page load', () => {
            // The migration has to actually persist, or it repeats forever --
            // except the legacy cookie is gone by then, so the value would be
            // lost instead.
            seedLegacyStore('plan=pro', { last_req: Util.getCurrentUnixTimestamp() });

            const beacons = [];
            const t = new OWATracker({ cookie_domain_set: true });
            t.setSiteId('cv-storage-test');
            pageView(t, beacons);

            nextPageLoad();
            const t2 = new OWATracker({ cookie_domain_set: true });
            t2.setSiteId('cv-storage-test');
            pageView(t2, beacons);

            expect(beacons[1].cv1).toBe('plan=pro');
        });
    });

    describe('across a session boundary', () => {

        /**
         * Set before the first pageview, and that pageview starts a session.
         * The value must survive: it was set FOR the session that is starting.
         */
        test('a variable set on this page load survives the session that starts', () => {
            tracker.setCustomVar(2, 'plan', 'pro', 'session');

            const beacons = [];
            pageView(tracker, beacons);

            expect(beacons[0].is_new_session).toBe(true);
            expect(OWA.getState('s', 'cv2')).toBe('plan=pro');

            // and a later pageview in the SAME session still carries it
            nextPageLoad();
            const next = new OWATracker({ cookie_domain_set: true });
            next.setSiteId('cv-storage-test');
            pageView(next, beacons);

            expect(beacons[1].is_new_session).toBeFalsy();
            expect(beacons[1].cv2).toBe('plan=pro');
        });

        /**
         * The other half. A value left by an EARLIER session must not be
         * inherited by a new one -- that is the leak the old 'b' cookie had,
         * and moving to 's' would have carried it over if the reset only
         * re-applied without clearing.
         */
        test('a variable from a previous session is not inherited', () => {
            // Persist a session, then let it expire. The value is in the
            // COOKIE, which is what makes it old -- this page load did not set
            // it.
            OWA.setState('s', 'cv3', 'stale=lastweek');
            OWA.setState('s', 'last_req', 1000);
            tracker.sendAccepted();
            nextPageLoad();

            const beacons = [];
            const fresh = new OWATracker({ cookie_domain_set: true });
            fresh.setSiteId('cv-storage-test');
            pageView(fresh, beacons);

            expect(beacons[0].is_new_session).toBe(true);
            expect(OWA.getState('s', 'cv3')).toBeFalsy();
            expect(beacons[0].cv3).toBeFalsy();
        });

        /**
         * Before the session is settled the value is in memory and NOT in the
         * cookie, and that is deliberate.
         *
         * An earlier attempt wrote it through to the cookie immediately, so a
         * browser closed before any pageview would not lose it. That durability
         * was illusory: at the next session boundary the written-through value
         * was indistinguishable from one the previous session had left, so the
         * boundary discarded it anyway. It bought nothing and cost the one
         * distinction the design depends on.
         */
        test('the value is held in memory, not written to the cookie', () => {
            tracker.setCustomVar(4, 'plan', 'pro', 'session');

            expect(OWA.getState('s', 'cv4')).toBe('plan=pro');
            expect(document.cookie).not.toContain('plan');
        });

        test('and reaches the cookie once the session is accepted', () => {
            tracker.setCustomVar(4, 'plan', 'pro', 'session');

            tracker.sendAccepted();

            expect(document.cookie).toContain('cv4');
        });

        /**
         * Once the session is settled there is nothing left to be ambiguous
         * about, so the reason for holding values back is gone and writes go
         * straight through. Withholding past that point would be a liability
         * rather than a safeguard: a variable set late on a long-lived page
         * would sit in memory with no second beacon coming to flush it.
         */
        test('a session-scoped variable set AFTER the pageview persists at once', () => {
            const beacons = [];
            pageView(tracker, beacons);

            tracker.setCustomVar(5, 'plan', 'pro', 'session');

            expect(document.cookie).toContain('cv5');
        });

        /**
         * The visitor store never waits on any of this. A visitor outlives
         * their sessions, so nothing about the session decision governs when
         * their state may be written -- and a visitor-scoped variable set on a
         * page that never tracks a pageview must still survive to the next one.
         */
        test('a visitor-scoped variable persists immediately, with no session settled', () => {
            expect(OWA.state.persistenceReleased).toEqual({});

            tracker.setCustomVar(6, 'tier', 'gold', 'visitor');

            expect(document.cookie).toContain('owa_v');
            expect(document.cookie).toContain('cv6');
        });
    });
});
