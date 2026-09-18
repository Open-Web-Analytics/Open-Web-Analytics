jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';
import { Util } from '../../modules/Base/src/common/Util.js';
import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { CommandQueue } from '../../modules/Base/src/tracker/CommandQueue.js';

/**
 * The two ways a configured cookie lifetime used to be lost before it was used.
 *
 * Both come from the same root: the value was applied EAGERLY, copied into the
 * store registry when the command arrived. Anything that rewrote the registry
 * afterwards, or happened before the command arrived at all, silently got the
 * shipped 364 days instead. The lifetime is read where it is used now, and the
 * configuration is carried into the constructor, which is what these pin.
 */

function captureCookies() {
    const written = [];
    jest.spyOn(Util, 'setCookie').mockImplementation((name, value, days) => {
        written.push({ name: name, value: value, days: days });
    });
    return written;
}

function persistedWrites(cookies, name) {
    return cookies.filter((c) => c.name === name && c.value !== '');
}

function reset() {
    OWA.initializeStateManager();
    OWA.state.stores = {};
    OWA.state.storeFormats = {};
    OWA.state.storeMeta = {};
    OWA.state.hydrated = {};
    OWA.state.persistenceReleased = {};
}

beforeEach(() => {
    reset();
    OWA.setSetting('ns', 'owa_');
    OWA.setSetting('cookie_domain', 'example.com');
    window.owa_baseUrl = 'https://owa.example.test/';
    Object.defineProperty(document, 'domain', {
        configurable: true,
        get() { return 'site.example'; },
    });
});

afterEach(() => {
    jest.restoreAllMocks();
    reset();
    delete window.owa_baseUrl;
    delete window.OWATracker;
    delete window.siteB;
});

/**
 * A second tracker on the page must not reset the first one's lifetime.
 *
 * registerStore() replaces storeMeta[name] wholesale and the constructor
 * re-registers the global 'v' and 'c' stores unconditionally, so a second
 * tracker configured with nothing used to take the visitor store back to 364
 * with nothing to put it back. Only the global stores can collide: a site-scoped
 * store is registered as 's_<siteId>', so two trackers never contend for it.
 */
describe('a second tracker does not reset the first ones configuration', () => {

    test('the visitor lifetime survives another trackers construction', () => {

        new OWATracker({ cookie_domain_set: true, site_id: 'site-a',
                         stateStoreExpirations: { v: 90 } });

        expect(OWA.state.getExpirationDays('v')).toBe(90);

        new OWATracker({ cookie_domain_set: true, site_id: 'site-b' });

        expect(OWA.state.getExpirationDays('v')).toBe(90);
    });

    test('and the cookie actually written still carries it', () => {

        const cookies = captureCookies();

        new OWATracker({ cookie_domain_set: true, site_id: 'site-a',
                         stateStoreExpirations: { v: 90 } });
        new OWATracker({ cookie_domain_set: true, site_id: 'site-b' });

        OWA.setState('v', 'visitor_id', '123', true);
        OWA.state.persist('v', true);

        const owa_v = persistedWrites(cookies, 'owa_v');

        expect(owa_v[owa_v.length - 1].days).toBe(90);
    });

    test('each tracker still gets its own session store lifetime', () => {

        // Site-scoped stores carry the site in the name, so they never collide
        // and each tracker's own configuration applies to its own store.
        new OWATracker({ cookie_domain_set: true, site_id: 'site-a',
                         stateStoreExpirations: { s: 30 } });
        new OWATracker({ cookie_domain_set: true, site_id: 'site-b',
                         stateStoreExpirations: { s: 7 } });

        expect(OWA.state.getExpirationDays('s_site-a')).toBe(30);
        expect(OWA.state.getExpirationDays('s_site-b')).toBe(7);
    });
});

/**
 * Storage migrations run DURING construction, and they write cookies.
 *
 * They are pegged to 'cookieDomainEstablished', which the constructor fires,
 * and they persist: collapseLegacyStores() folds the legacy 'b' store into the
 * session store, and the per-site migration moves a session store to its new
 * name. Every command, setOption included, arrives after construction returns.
 *
 * So a returning visitor holding a legacy cookie would have the migrated one
 * written at the shipped 364 days even when the snippet asks for 90 -- which is
 * exactly the visitor this configuration exists for. identityFor() reads the
 * option ahead out of the queue for the same reason it already reads the site id.
 */
describe('configuration reaches the constructor, so migrations honour it', () => {

    test('the option is read ahead out of the queue', () => {

        const q = new CommandQueue();

        q.loadCmds([
            ['setSiteId', 'ahead-site'],
            ['setOption', 'stateStoreExpirations', { v: 90 }],
            ['setOption', 'cookiePersistence', false],
            ['trackPageView'],
        ]);

        const identity = q.identityFor('OWATracker', ['setSiteId', 'ahead-site']);

        expect(identity.site_id).toBe('ahead-site');
        expect(identity.stateStoreExpirations).toEqual({ v: 90 });
        expect(identity.cookiePersistence).toBe(false);
    });

    test('an absent option is not invented', () => {

        const q = new CommandQueue();
        q.loadCmds([['setSiteId', 'plain-site'], ['trackPageView']]);

        const identity = q.identityFor('OWATracker', ['setSiteId', 'plain-site']);

        // undefined, not a default: the constructor's own defaults are the one
        // place the shipped values live.
        expect(identity.stateStoreExpirations).toBeUndefined();
        expect(identity.cookiePersistence).toBeUndefined();
    });

    test('a false persistence value is carried, not mistaken for absent', () => {

        // argFor() reports "nothing found" as '', which cannot distinguish a
        // command that sets false. optionFor() uses undefined for that reason.
        const q = new CommandQueue();
        q.loadCmds([['setOption', 'cookiePersistence', false]]);

        expect(q.identityFor('OWATracker', []).cookiePersistence).toBe(false);
    });

    test('the option is in place before the constructor registers any store', () => {

        // The proof that matters: built the way the queue builds it, the
        // registry already answers with the configured lifetime with no command
        // having been applied yet.
        const q = new CommandQueue();
        q.loadCmds([
            ['setSiteId', 'ctor-site'],
            ['setOption', 'stateStoreExpirations', { v: 90, s: 30 }],
        ]);
        q.process();

        expect(OWA.state.getExpirationDays('v')).toBe(90);
        expect(OWA.state.getExpirationDays('s_ctor-site')).toBe(30);
    });

    test('a named tracker only picks up options addressed to it', () => {

        const q = new CommandQueue();
        q.loadCmds([
            ['setOption', 'stateStoreExpirations', { v: 90 }],
            ['siteB.setSiteId', 'b'],
        ]);

        expect(q.identityFor('siteB', []).stateStoreExpirations).toBeUndefined();
        expect(q.identityFor('OWATracker', []).stateStoreExpirations).toEqual({ v: 90 });
    });
});
