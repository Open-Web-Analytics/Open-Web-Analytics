import { OWATracker } from '../../modules/base/src/tracker/Tracker.js';
import { OWA_instance as OWA } from '../../modules/base/src/common/owa.js';

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

    test('marks cookie_domain_set so the automatic path will not run again', () => {
        setDocumentDomain('example.com');
        const t = newTracker();
        expect(t.getOption('cookie_domain_set')).not.toBe(true);
        t.setCookieDomain();
        expect(t.getOption('cookie_domain_set')).toBe(true);
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

    test('falls back to document.domain when nothing has been pinned', () => {
        OWA.setSetting('cookie_domain', false);
        setDocumentDomain('fallback.example');
        const t = newTracker();
        // No option, no global setting -> the raw document.domain (unnormalized;
        // this is the pre-first-event read used only as a last resort).
        expect(t.getCookieDomain()).toBe('fallback.example');
    });
});

describe('automatic resolution fires on the first tracked event', () => {

    test('trackEvent triggers setCookieDomain when cookie_domain_set is not true', () => {
        setDocumentDomain('www.auto.example');
        const t = newTracker();
        t.setSiteId('cd-auto-site');
        // Guard: the automatic path has not run yet.
        expect(t.getOption('cookie_domain_set')).not.toBe(true);

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
