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
 * Session-only cookies, via the cookiePersistence option. Issue #941.
 *
 * cookie_persistence has made SERVER-set cookies session-only since 2016
 * (CoreAPI::setCookie sets $expires = 0). The JavaScript tracker never read it
 * -- there is no reference to the setting anywhere under modules/Base/src -- so
 * an install that turned persistence off still got a year-long visitor cookie
 * from every page the tracker ran on. A privacy setting that reports success and
 * does nothing is worse than one that is missing, because nobody goes looking.
 *
 * WHY THIS IS NOT "EXPIRATION 0". writePersistedStore() turns any falsy
 * expiration into the shipped 364 for permanent stores, so a configured zero is
 * indistinguishable from "nothing configured" and would silently become a year
 * -- on the visitor id, which is the cookie where that matters most. The flag
 * is separate precisely so those two cannot collapse into one value, and the
 * test below pins that.
 *
 * The mechanism at the bottom is Util.setCookie, which emits an expires
 * attribute only for a TRUTHY days. Zero therefore produces a cookie with no
 * expiry -- a session cookie -- with no change to Util at all.
 */

function captureCookies() {
    const written = [];

    jest.spyOn(Util, 'setCookie').mockImplementation((name, value, days) => {
        written.push({ name: name, value: value, days: days });
    });

    return written;
}

/** Writes that STORE something, as opposed to the erasures. */
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
});

afterEach(() => {
    jest.restoreAllMocks();
    reset();
    delete window.owa_baseUrl;
    delete window.OWATracker;
});

describe('cookie persistence defaults to on', () => {

    test('a tracker that is told nothing still writes a persistent cookie', () => {

        const cookies = captureCookies();
        new OWATracker({ cookie_domain_set: true, site_id: 'cp-site' });

        OWA.setState('v', 'visitor_id', '123', true);
        OWA.state.persist('v', true);

        const owa_v = persistedWrites(cookies, 'owa_v');

        expect(owa_v[owa_v.length - 1].days).toBe(364);
    });
});

describe('cookiePersistence false makes every store a session cookie', () => {

    test('the visitor cookie is written with no lifetime', () => {

        const cookies = captureCookies();
        const tracker = new OWATracker({ cookie_domain_set: true, site_id: 'cp-site' });

        tracker.setOption('cookiePersistence', false);

        OWA.setState('v', 'visitor_id', '123', true);
        OWA.state.persist('v', true);

        const owa_v = persistedWrites(cookies, 'owa_v');

        expect(owa_v.length).toBeGreaterThan(0);
        // Falsy days is what makes Util.setCookie omit the expires attribute.
        expect(owa_v[owa_v.length - 1].days).toBeFalsy();
    });

    /**
     * The case the separate flag exists for.
     *
     * A permanent write with no configured expiration gets the 364-day fallback.
     * If persistence were expressed as "expiration 0" that fallback would
     * overwrite it, and the visitor id -- the one cookie this setting is most
     * often turned off FOR -- would quietly live a year anyway.
     */
    test('it beats the 364-day fallback for a permanent store', () => {

        const cookies = captureCookies();
        const tracker = new OWATracker({ cookie_domain_set: true, site_id: 'cp-site' });

        tracker.setOption('cookiePersistence', false);

        // is_perminant = true is the path that applies the fallback
        OWA.state.writePersistedStore('v', { visitor_id: '123' }, true);

        const owa_v = persistedWrites(cookies, 'owa_v');

        expect(owa_v[owa_v.length - 1].days).toBeFalsy();
    });

    test('it beats an explicitly configured lifetime too', () => {

        const cookies = captureCookies();
        const tracker = new OWATracker({ cookie_domain_set: true, site_id: 'cp-site' });

        // Both settings at once. Persistence is the stronger statement: asking
        // for no cookie that outlives the session cannot be satisfied by one
        // that lives 90 days.
        tracker.setOption('stateStoreExpirations', { v: 90 });
        tracker.setOption('cookiePersistence', false);

        OWA.setState('v', 'visitor_id', '123', true);
        OWA.state.persist('v', true);

        const owa_v = persistedWrites(cookies, 'owa_v');

        expect(owa_v[owa_v.length - 1].days).toBeFalsy();
    });

    test('order of the two commands does not change the outcome', () => {

        const cookies = captureCookies();
        const tracker = new OWATracker({ cookie_domain_set: true, site_id: 'cp-site' });

        tracker.setOption('cookiePersistence', false);
        tracker.setOption('stateStoreExpirations', { v: 90 });

        OWA.setState('v', 'visitor_id', '123', true);
        OWA.state.persist('v', true);

        const owa_v = persistedWrites(cookies, 'owa_v');

        expect(owa_v[owa_v.length - 1].days).toBeFalsy();
    });
});

describe('turning persistence back on', () => {

    test('true restores the configured lifetime', () => {

        const cookies = captureCookies();
        const tracker = new OWATracker({ cookie_domain_set: true, site_id: 'cp-site' });

        tracker.setOption('stateStoreExpirations', { v: 90 });
        tracker.setOption('cookiePersistence', false);
        tracker.setOption('cookiePersistence', true);

        OWA.setState('v', 'visitor_id', '123', true);
        OWA.state.persist('v', true);

        const owa_v = persistedWrites(cookies, 'owa_v');

        expect(owa_v[owa_v.length - 1].days).toBe(90);
    });

    test.each([
        ['undefined', undefined],
        ['null', null],
        ['the string "false"', 'false'],
        ['zero', 0],
    ])('%s does not turn persistence off', (_label, value) => {

        const cookies = captureCookies();
        const tracker = new OWATracker({ cookie_domain_set: true, site_id: 'cp-site' });

        // Only an explicit false turns it off. Silently downgrading every
        // visitor to a session cookie because a value failed to parse is data
        // loss; "unchanged" is the safe reading of something unparseable. The
        // server sends a real JSON false, so this costs nothing.
        tracker.setOption('cookiePersistence', value);

        OWA.setState('v', 'visitor_id', '123', true);
        OWA.state.persist('v', true);

        const owa_v = persistedWrites(cookies, 'owa_v');

        expect(owa_v[owa_v.length - 1].days).toBe(364);
    });
});

/**
 * Deletion must keep working.
 *
 * eraseCookie() expires a cookie by setting it with days -1 (and -2 on the
 * alternate domain), which relies on Util.setCookie EMITTING an expires
 * attribute. That is exactly the attribute session cookies do without, so a
 * blanket rule inside Util.setCookie -- which is what issue #941's draft patch
 * proposed -- would have made cookies undeletable. Implementing it in
 * writePersistedStore instead is what keeps these two apart.
 */
describe('cookie deletion is unaffected', () => {

    test('eraseCookie still expires in the past while persistence is off', () => {

        const cookies = captureCookies();
        const tracker = new OWATracker({ cookie_domain_set: true, site_id: 'cp-site' });

        tracker.setOption('cookiePersistence', false);
        Util.eraseCookie('owa_v', 'example.com');

        const erasures = cookies.filter((c) => c.name === 'owa_v' && c.value === '');

        expect(erasures.length).toBeGreaterThan(0);
        expect(erasures[0].days).toBeLessThan(0);
    });
});

/**
 * Driven through the real command queue, in snippet order.
 */
describe('the snippet ordering puts persistence in place before the first cookie', () => {

    beforeEach(() => {
        window.owa_baseUrl = 'https://owa.example.test/';
        Object.defineProperty(document, 'domain', {
            configurable: true,
            get() { return 'site.example'; },
        });
    });

    test('a visitor cookie from the first trackPageView carries no lifetime', () => {

        const cookies = captureCookies();
        const OrigImage = global.Image;
        global.Image = class { set src(v) {} };

        try {
            const q = new CommandQueue();

            // Exactly what js_log_tag.php emits, in the order it emits it.
            q.loadCmds([
                ['setSiteId', 'cp-site'],
                ['setOption', 'stateStoreExpirations', { v: 90 }],
                ['setOption', 'cookiePersistence', false],
                ['trackPageView'],
            ]);
            q.process();

            const owa_v = persistedWrites(cookies, 'owa_v');

            expect(owa_v.length).toBeGreaterThan(0);
            // Every write, not just the last: no cookie may ever have been
            // written with a lifetime, not even the first one.
            owa_v.forEach((c) => expect(c.days).toBeFalsy());
        } finally {
            global.Image = OrigImage;
        }
    });
});
