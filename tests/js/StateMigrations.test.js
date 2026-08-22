/**
 * @jest-environment jsdom
 * @jest-environment-options {"url": "https://example.com/p"}
 */
jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';
import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { StateManager } from '../../modules/Base/src/common/StateManager.js';
import { Util } from '../../modules/Base/src/common/Util.js';

/**
 * Storage migrations, and why they are a seam rather than a set of fallbacks.
 *
 * Compatibility handled at the READ side spreads outwards. Every reader grows a
 * fallback; every fallback is a place the old shape can come back; and none of
 * them are ever removed, because nobody can demonstrate the last visitor holding
 * the old shape has gone. The legacy 'b' custom-variable store is the worked
 * example -- reading it as a fallback did keep visitors from losing values, and
 * also carried a session-boundary leak into the store it was being moved away
 * from, because nothing ever cleared it.
 *
 * A migration finishes. It normalises storage BEFORE anything reads it, so
 * readers only ever see the current shape and downstream code never learns a
 * migration happened at all.
 *
 * They are pegged to 'cookieDomainEstablished' because the domain hash is part
 * of what makes a cookie readable, and that is the whole of what a cookie
 * migration depends on. Pegging them to the session decision would hand them a
 * dependency they do not have -- and worse, would make a migrated value look
 * like something the current page load set.
 *
 * Migrations must be IDEMPOTENT. The announcement fires once per tracker init,
 * and a page constructing two trackers runs them twice; a run that ends part way
 * is retried on the next page load and has to tolerate finding its own
 * half-finished work.
 */

function coldPage() {
    // A fresh state manager, which also resets the registry to the built-ins.
    OWA.state = new StateManager();
    ['v', 's', 'c', 'b', 'd'].forEach((store) => OWA.clearState(store));
    document.cookie.split(';').forEach((c) => {
        const name = c.split('=')[0].trim();
        if (name) { document.cookie = `${name}=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/`; }
    });
    OWA.state.cookies = Util.readAllCookies();
}

function newTracker() {
    const t = new OWATracker({ cookie_domain_set: true });
    t.setSiteId('migration-site');
    return t;
}

describe('state storage migrations', () => {

    beforeEach(() => { coldPage(); });
    afterEach(() => { coldPage(); });

    test('the legacy store collapse ships as a registered migration', () => {
        expect(OWA.state.migrations.map((m) => m.name))
            .toContain('collapse-legacy-stores');
    });

    test('a registered migration runs when the cookie domain is established', () => {
        let ran = 0;
        OWA.registerStateMigration('probe', () => { ran++; });

        newTracker();

        expect(ran).toBe(1);
    });

    test('it is handed the state manager to work through', () => {
        let seen = null;
        OWA.registerStateMigration('probe', (state) => { seen = state; });

        newTracker();

        expect(seen).toBe(OWA.state);
    });

    /**
     * The property the whole seam exists for: by the time anything reads state,
     * storage is already in its current shape. Here a migration writes a session
     * cookie and the pageview that follows picks it up -- without the tracker
     * knowing a migration was involved.
     */
    test('a migration lands before anything downstream reads', () => {
        OWA.registerStateMigration('seed-session', (state) => {
            const store = {
                sid: 'migrated-session',
                last_req: Util.getCurrentUnixTimestamp(),
            };
            if (OWA.getSetting('hashCookiesToDomain')) {
                store.cdh = Util.getCookieDomainHash(OWA.getSetting('cookie_domain'));
            }
            state.writePersistedStore('s', store, true);
            state.cookies = Util.readAllCookies();
        });

        const t = newTracker();
        let beacon = null;
        t.logEvent = (p) => { beacon = { ...p }; };
        t.trackPageView(location.href);

        expect(beacon.session_id).toBe('migrated-session');
        expect(beacon.is_new_session).toBeFalsy();
    });

    /**
     * A page that cannot migrate its cookies can still track. Letting a broken
     * migration take the tracker down would turn a storage problem into total
     * data loss, and the next page load gets to try again anyway.
     */
    test('a migration that throws does not stop the others', () => {
        const ran = [];
        OWA.registerStateMigration('boom', () => { throw new Error('migration failed'); });
        OWA.registerStateMigration('after', () => { ran.push('after'); });

        newTracker();

        expect(ran).toEqual(['after']);
    });

    test('...nor does it stop the page being tracked', () => {
        OWA.registerStateMigration('boom', () => { throw new Error('migration failed'); });

        const t = newTracker();
        let beacon = null;
        t.logEvent = (p) => { beacon = { ...p }; };
        t.trackPageView(location.href);

        expect(beacon).not.toBeNull();
        expect(beacon.session_id).toBeTruthy();
    });

    test('migrations run again on a second tracker, so they must be idempotent', () => {
        let ran = 0;
        OWA.registerStateMigration('probe', () => { ran++; });

        newTracker();
        newTracker();

        expect(ran).toBe(2);
    });
});
