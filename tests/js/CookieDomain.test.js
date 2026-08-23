import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';
import { Util } from '../../modules/Base/src/common/Util.js';
import { StateManager } from '../../modules/Base/src/common/StateManager.js';

/**
 * Cookie-domain resolution (setCookieDomain / getCookieDomain).
 *
 * The domain a tracker sets its cookies on decides whether a visitor is seen as
 * one person across a site's subdomains or as a new visitor on each host. The
 * tracker resolves it in two ways (see the "Cookie Domain and Sub Domain
 * Tracking" wiki section):
 *
 *   - AUTOMATIC: on the first tracked event, trackEvent() calls setCookieDomain()
 *     with no argument unless cookie_domain_set is already true. With no argument
 *     the domain is taken from document.domain, with two adjustments: a leading
 *     "www." is stripped (so www.example.com and example.com share cookies), but
 *     any other subdomain is kept as-is (shop.example.com stays scoped to the
 *     shop host).
 *   - EXPLICIT: a site calls setCookieDomain('example.com') to pin a higher-level
 *     domain so cookies are shared across ALL subdomains. An explicit value is
 *     used verbatim -- notably the "www." auto-strip does NOT apply, so an
 *     explicit www.example.com stays scoped to that host.
 *
 * In both cases the stored value is normalized to a single leading "." (the
 * form a browser needs to scope a cookie to a domain and its subdomains), so a
 * caller may pass the domain with or without the leading dot.
 *
 * setCookieDomain writes BOTH the per-tracker option and the global OWA
 * cookie_domain setting (the state layer reads the latter), and flips
 * cookie_domain_set so the automatic path won't later overwrite an explicit
 * choice. getCookieDomain reads back option -> global setting -> document.domain.
 */

function setDocumentDomain(domain) {
    Object.defineProperty(document, 'domain', {
        configurable: true,
        get() { return domain; },
    });
}

// A pristine tracker with no cookie plumbing pre-set, so setCookieDomain is the
// only thing that populates it. (The other suites pass cookie_domain_set:true to
// SKIP this logic; here it IS the logic under test.)
function newTracker() {
    return new OWATracker({});
}

afterEach(() => {
    // setCookieDomain writes the global OWA cookie_domain setting; clear it so a
    // resolved domain can't leak into the next test's getCookieDomain fallback.
    OWA.setSetting('cookie_domain', false);
});

describe('setCookieDomain: automatic (no argument) resolution from document.domain', () => {

    test('strips a leading www. so www.example.com and example.com share cookies', () => {
        setDocumentDomain('www.example.com');
        const t = newTracker();
        t.setCookieDomain();
        expect(t.getOption('cookie_domain')).toBe('.example.com');
    });

    test('keeps a non-www subdomain, scoping cookies to that host', () => {
        setDocumentDomain('shop.example.com');
        const t = newTracker();
        t.setCookieDomain();
        // The 'www.'-only strip means other subdomains are NOT collapsed to the
        // apex -- shop.example.com keeps its own cookie scope.
        expect(t.getOption('cookie_domain')).toBe('.shop.example.com');
    });

    test('leaves an apex domain unchanged (aside from the leading dot)', () => {
        setDocumentDomain('example.com');
        const t = newTracker();
        t.setCookieDomain();
        expect(t.getOption('cookie_domain')).toBe('.example.com');
    });

    test('the CONSTRUCTOR resolves it, so nothing is written against an unknown domain', () => {
        // This used to assert the opposite -- that cookie_domain_set was still
        // false after construction, because resolution waited for the first
        // tracked event. That wait was a bug: every store stamps a hash of the
        // cookie domain when it is WRITTEN, and readPersistedStore() refuses a
        // store whose hash does not match, so a custom var set before the first
        // event (the documented order) was stamped against the wrong domain and
        // silently lost on the next page load.
        setDocumentDomain('example.com');
        const t = newTracker();

        expect(t.getOption('cookie_domain_set')).toBe(true);
        expect(t.getOption('cookie_domain')).toBe('.example.com');
    });

    test('an explicit domain still overrides the resolved one', () => {
        setDocumentDomain('shop.example.com');
        const t = newTracker();
        expect(t.getOption('cookie_domain')).toBe('.shop.example.com');

        t.setCookieDomain('example.com');
        expect(t.getOption('cookie_domain')).toBe('.example.com');
        expect(t.getOption('cookie_domain_set')).toBe(true);
    });

    test('a tracker with no document.domain leaves it unresolved rather than throwing', () => {
        // jsdom, workers, and any non-DOM context. The automatic path runs at
        // construction now, so it is reached far more often than it was.
        setDocumentDomain('');
        expect(() => newTracker()).not.toThrow();

        const t = newTracker();
        expect(t.getOption('cookie_domain_set')).not.toBe(true);
    });
});

describe('setCookieDomain: explicit domain', () => {

    test('pins a higher-level domain to share cookies across subdomains', () => {
        // A site served on shop.example.com that wants cookies shared with
        // www.example.com / blog.example.com pins the apex explicitly.
        setDocumentDomain('shop.example.com');
        const t = newTracker();
        t.setCookieDomain('example.com');
        expect(t.getOption('cookie_domain')).toBe('.example.com');
    });

    test('normalizes an already-dotted domain to a single leading dot', () => {
        setDocumentDomain('shop.example.com');
        const t = newTracker();
        t.setCookieDomain('.example.com');
        expect(t.getOption('cookie_domain')).toBe('.example.com');
    });

    test('does NOT strip www. from an explicit domain (auto-strip is auto-only)', () => {
        // Passing an explicit value opts out of the www auto-strip: it is honored
        // verbatim (plus the leading-dot normalization).
        setDocumentDomain('foo.com');
        const t = newTracker();
        t.setCookieDomain('www.example.com');
        expect(t.getOption('cookie_domain')).toBe('.www.example.com');
    });

    test('writes the global OWA cookie_domain setting the state layer reads', () => {
        setDocumentDomain('shop.example.com');
        const t = newTracker();
        t.setCookieDomain('example.com');
        // The state/cookie machinery reads the global setting, not the per-tracker
        // option -- so setCookieDomain must update both.
        expect(OWA.getSetting('cookie_domain')).toBe('.example.com');
    });
});

describe('getCookieDomain resolution order', () => {

    test('returns the per-tracker option once set', () => {
        setDocumentDomain('shop.example.com');
        const t = newTracker();
        t.setCookieDomain('example.com');
        expect(t.getCookieDomain()).toBe('.example.com');
    });

    test('falls back to document.domain only when nothing could be resolved', () => {
        // The raw document.domain read is a last resort, and it is now genuinely
        // last: the constructor resolves and normalizes the domain, so a tracker
        // that HAS one returns that. Reaching the raw fallback means resolution
        // did not happen -- no document.domain at construction.
        OWA.setSetting('cookie_domain', false);
        setDocumentDomain('');
        const t = newTracker();

        setDocumentDomain('fallback.example');
        expect(t.getCookieDomain()).toBe('fallback.example');
    });

    test('a resolved domain is returned normalized, not raw', () => {
        OWA.setSetting('cookie_domain', false);
        setDocumentDomain('fallback.example');
        const t = newTracker();

        expect(t.getCookieDomain()).toBe('.fallback.example');
    });
});

describe('automatic resolution has already happened by the first tracked event', () => {

    test('trackEvent finds the domain already resolved, and leaves it alone', () => {
        setDocumentDomain('www.auto.example');
        const t = newTracker();
        t.setSiteId('cd-auto-site');
        // The constructor already resolved it -- trackEvent's call is a safety
        // net for a tracker built without a resolvable domain, not the path.
        expect(t.getOption('cookie_domain_set')).toBe(true);
        expect(t.getOption('cookie_domain')).toBe('.auto.example');

        // Fire an event through the tracker (Image stubbed so no network). The
        // first event's trackEvent() should auto-resolve the cookie domain.
        const OrigImage = global.Image;
        global.Image = class { set src(v) {} };
        try {
            t.trackPageView('https://www.auto.example/p');
        } finally {
            global.Image = OrigImage;
        }

        expect(t.getOption('cookie_domain_set')).toBe(true);
        // www. stripped by the automatic resolution.
        expect(t.getOption('cookie_domain')).toBe('.auto.example');
    });
});

/**
 * THE DEFECT the timing change exists to fix, tested through its consequence
 * rather than through when a flag gets set.
 *
 * Stores stamp a hash of the cookie domain (cdh) into their value at WRITE
 * time, and readPersistedStore() refuses a store whose hash does not match the
 * current domain. While the domain was resolved lazily -- on the first tracked
 * event -- anything written before that was stamped against a domain that was
 * not yet the real one. The next page load then rejected it.
 *
 * The order this breaks is the documented one: set your custom variables, then
 * track. So a visitor-scoped variable, which is supposed to last a year,
 * survived exactly until the page unloaded.
 */
describe('state written before the first event survives the next page load', () => {

    beforeEach(() => {
        OWA.setSetting('cookie_domain', false);
        Object.defineProperty(document, 'domain', {
            configurable: true,
            get() { return 'localhost'; },
        });
        OWA.initializeStateManager();
        OWA.state.stores = {};
        OWA.state.cookies = Util.readAllCookies();
    });

    test('a visitor custom var set BEFORE any tracking is readable on the next page', () => {
        const first = new OWATracker({ site_id: 'domain-persist-site' });
        first.setCustomVar(1, 'Plan', 'Pro', 'visitor');

        // The page went away; cookies survive, memory does not.
        OWA.state = new StateManager();
        OWA.state.cookies = Util.readAllCookies();

        expect(OWA.getPersistedState('v', 'cv1')).toBe('Plan=Pro');
    });

    test('the value it was stamped with is the domain it is later read against', () => {
        const t = new OWATracker({ site_id: 'domain-persist-site' });
        t.setCustomVar(2, 'Tier', 'Gold', 'visitor');

        const stamped = OWA.getState('v', 'cdh');

        expect(stamped).toBe(Util.getCookieDomainHash(OWA.getSetting('cookie_domain')));
        expect(stamped).not.toBe(Util.getCookieDomainHash(undefined));
    });
});
