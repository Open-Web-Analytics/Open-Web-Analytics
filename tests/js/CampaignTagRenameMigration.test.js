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
 * Carrying campaign attribution across the tagged_* rename.
 *
 * The tracker and the server ship in one release, so there is no version skew
 * between them. Cookies are not part of a release: a visitor already mid
 * session holds a session store keyed source/medium/campaign, and the new code
 * reads tagged_source/tagged_medium/tagged_campaign. Without a migration their
 * attribution silently becomes direct on the very next page -- no error, and a
 * campaign that simply appears to stop converting.
 *
 * A migration rather than read-side fallbacks, for the reason StateMigrations
 * sets out: a fallback never expires, and nobody can ever demonstrate the last
 * visitor holding the old shape has gone.
 */

const SITE = 'tag-rename-site';
const STORE = `s_${SITE}`;

function coldPage() {
    OWA.state = new StateManager();
    ['v', 's', 'c', 'b', 'd', STORE].forEach((store) => OWA.clearState(store));
    document.cookie.split(';').forEach((c) => {
        const name = c.split('=')[0].trim();
        if (name) { document.cookie = `${name}=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/`; }
    });
    OWA.state.cookies = Util.readAllCookies();
}

/**
 * Seed the cookie a PREVIOUS release's tracker left behind.
 *
 * Written straight to document.cookie rather than through the state manager:
 * the per-site session store is not registered until the tracker constructs
 * itself, so a seed that runs any earlier has nowhere to write. A cookie is
 * what actually survives a release anyway, which is the situation being tested.
 */
function seedLegacySessionStore(extra = {}) {
    const store = {
        sid: 'carried-session',
        last_req: Util.getCurrentUnixTimestamp(),
        source: 'news',
        medium: 'email',
        campaign: 'summer',
        search_terms: 'blue widgets',
        ad: 'creative-a',
        ad_type: 'cpc',
        sv: 1,
        ...extra,
    };
    if (OWA.getSetting('hashCookiesToDomain')) {
        store.cdh = Util.getCookieDomainHash(OWA.getSetting('cookie_domain'));
    }
    document.cookie = `owa_${STORE}=${encodeURIComponent(JSON.stringify(store))};path=/`;
    OWA.state.cookies = Util.readAllCookies();
}

/** The persisted cookie, which is what a migration actually rewrites. */
function persisted() {
    const raw = Util.readAllCookies()[`owa_${STORE}`];
    return raw ? JSON.parse(decodeURIComponent(raw)) : {};
}

function newTracker() {
    return new OWATracker({ cookie_domain_set: true, site_id: SITE });
}

describe('campaign tag rename migration', () => {

    beforeEach(() => { coldPage(); });
    afterEach(() => { coldPage(); });

    test('it ships as a registered migration', () => {
        newTracker();
        expect(OWA.state.migrations.map((m) => m.name))
            .toContain('campaign-tags-renamed');
    });

    test('a mid-session visitor keeps their attribution under the new keys', () => {
        seedLegacySessionStore();
        newTracker();

        expect(persisted().tagged_source).toBe('news');
        expect(persisted().tagged_medium).toBe('email');
        expect(persisted().tagged_campaign).toBe('summer');
        expect(persisted().tagged_terms).toBe('blue widgets');
        expect(persisted().tagged_ad).toBe('creative-a');
        expect(persisted().tagged_ad_type).toBe('cpc');
    });

    /**
     * search_terms is the one key whose new name is not its old name with a
     * prefix -- it becomes tagged_terms, following the v2 naming. A migration
     * that just prefixes everything drops it, and dropping it is invisible:
     * the term is simply absent and the session reads as untagged.
     */
    test('search_terms is carried even though it is not a plain prefix', () => {
        seedLegacySessionStore();
        newTracker();

        expect(persisted().tagged_terms).toBe('blue widgets');
        expect(persisted().terms).toBeFalsy();
    });

    test('the old keys are gone afterwards, so nothing can read the old shape', () => {
        seedLegacySessionStore();
        newTracker();

        ['source', 'medium', 'campaign', 'search_terms', 'ad', 'ad_type']
            .forEach((key) => {
                expect(persisted()[key]).toBeFalsy();
            });
    });

    test('everything else in the store is left alone', () => {
        seedLegacySessionStore();
        newTracker();

        expect(persisted().sid).toBe('carried-session');
    });

    /**
     * The announcement fires once per tracker init, so a page constructing two
     * trackers runs every migration twice -- and a run that ends part way is
     * retried on the next page load and must tolerate its own half-finished
     * work.
     */
    test('running it twice does not undo the first run', () => {
        seedLegacySessionStore();
        newTracker();
        newTracker();

        expect(persisted().tagged_source).toBe('news');
        expect(persisted().tagged_campaign).toBe('summer');
    });

    /**
     * A visitor who has been here since the upgrade already holds the new keys.
     * If a stale legacy key is also present -- a second tab wrote one, a partial
     * earlier run left one -- the current value must win, or the migration
     * reverts them to an older campaign touch.
     */
    test('a value already under the new name is not clobbered by a stale old one', () => {
        seedLegacySessionStore({ tagged_source: 'current', tagged_campaign: 'autumn' });
        newTracker();

        expect(persisted().tagged_source).toBe('current');
        expect(persisted().tagged_campaign).toBe('autumn');
        expect(persisted().source).toBeFalsy();
    });

    test('a store with no campaign keys is left untouched', () => {
        const store = { sid: 'plain-session', last_req: Util.getCurrentUnixTimestamp(), sv: 1 };
        if (OWA.getSetting('hashCookiesToDomain')) {
            store.cdh = Util.getCookieDomainHash(OWA.getSetting('cookie_domain'));
        }
        document.cookie = `owa_${STORE}=${encodeURIComponent(JSON.stringify(store))};path=/`;
        OWA.state.cookies = Util.readAllCookies();

        newTracker();

        expect(persisted().sid).toBe('plain-session');
        expect(persisted().tagged_source).toBeFalsy();
    });

    /** The migrated tags must reach the beacon, which is the point of carrying them. */
    test('the carried tags are sent on the next beacon', () => {
        seedLegacySessionStore();

        const t = newTracker();
        let beacon = null;
        t.logEvent = (p) => { beacon = { ...p }; };
        t.trackPageView(location.href);

        expect(beacon.tagged_source).toBe('news');
        expect(beacon.tagged_campaign).toBe('summer');
        expect(beacon.source).toBeUndefined();
    });
});
