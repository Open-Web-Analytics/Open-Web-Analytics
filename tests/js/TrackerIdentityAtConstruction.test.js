jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';
import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { CommandQueue } from '../../modules/Base/src/tracker/CommandQueue.js';

/**
 * A tracker is built knowing which site it is.
 *
 * It did not used to be. The command queue constructs a tracker on the FIRST
 * command naming it and applies that command afterwards, so the entire
 * constructor body ran with siteId still ''. Everything that matters happens in
 * that body: the five registerStateStore() calls, the 'cookieDomainEstablished'
 * action that storage migrations peg to, and 'tracker.init'. A store cannot be
 * scoped to a site nobody has named yet.
 *
 * GA has no equivalent gap because the property id is an argument to the call
 * that CREATES the tag -- gtag('config', ID) -- never a later setter. Measured
 * against a real GA tag: an event fired before config() is dropped entirely,
 * while one carrying send_to fires regardless of ordering.
 *
 * ORDER MUST NOT MATTER, and that is the point of the look-ahead. OWA's own
 * snippet template puts setDebug ahead of setSiteId whenever the install is in
 * development mode, and setApiEndpoint ahead of it whenever that option is set
 * -- and those snippets are already pasted into sites that will never be
 * regenerated. Trusting "setSiteId is first" would fix the tidy case and leave
 * the real ones broken. loadCmds() receives the whole array before process()
 * starts shifting it, so the queue looks the identity up instead.
 */
describe('a tracker is constructed knowing its site', () => {

    beforeEach(() => {
        window.owa_baseUrl = 'https://owa.example.test/';
        Object.defineProperty(document, 'domain', {
            configurable: true,
            get() { return 'site.example'; },
        });
    });

    afterEach(() => {
        delete window.owa_baseUrl;
        delete window.OWATracker;
        delete window.siteB;
    });

    test('the constructor accepts a site id directly', () => {
        const t = new OWATracker({ cookie_domain_set: true, site_id: 'built-in-site' });

        expect(t.getSiteId()).toBe('built-in-site');
    });

    test('setSiteId still works, as reconfigure', () => {
        const t = new OWATracker({ cookie_domain_set: true, site_id: 'first' });
        t.setSiteId('second');

        expect(t.getSiteId()).toBe('second');
    });

    test('the queue builds the tracker with its site id', () => {
        const q = new CommandQueue();
        q.push(['setSiteId', 'queued-site']);

        expect(window.OWATracker.getSiteId()).toBe('queued-site');
    });

    /**
     * THE CASE THE TEMPLATE ACTUALLY PRODUCES. A development install emits
     * setDebug first, so the tracker is constructed on THAT command -- and
     * without the look-ahead it is born with no site.
     */
    test('the site id reaches the constructor even when it is not the first command', () => {
        const q = new CommandQueue();
        q.loadCmds([
            ['setDebug', true],                 // what a dev-mode snippet emits first
            ['setApiEndpoint', 'https://owa.example.test/api/'],
            ['setSiteId', 'late-site'],
        ]);
        q.process();

        expect(window.OWATracker.getSiteId()).toBe('late-site');

        // getSiteId() alone proves nothing: setSiteId runs after construction
        // either way, so it reads back correctly even if the constructor never
        // saw it. The STORE NAME is the discriminating evidence, because it is
        // resolved inside the constructor -- a tracker that was built without
        // its site registers a bare 's' first and only re-registers later.
        expect(OWA.state.storeMeta).toHaveProperty('s_late-site');
        expect(OWA.state.storeMeta).not.toHaveProperty('s');
    });

    test('config creates a tracker for a site, gtag style', () => {
        const q = new CommandQueue();
        q.push(['config', 'configured-site']);

        expect(window.OWATracker.getSiteId()).toBe('configured-site');
    });

    test('a dotted config creates a SECOND, independently named tracker', () => {
        const q = new CommandQueue();
        q.loadCmds([
            ['config', 'site-a'],
            ['siteB.config', 'site-b'],
        ]);
        q.process();

        expect(window.OWATracker.getSiteId()).toBe('site-a');
        expect(window.siteB.getSiteId()).toBe('site-b');
        expect(window.OWATracker).not.toBe(window.siteB);
    });

    /**
     * The cookie domain is the same ordering trap as the site id, and a worse
     * one: every store stamps a hash of it at WRITE time, and
     * readPersistedStore() refuses a store whose hash does not match. Left to
     * the lazy path in trackEvent(), anything written before the first event is
     * stamped against a domain that is not yet the real one.
     */
    test('a queued setCookieDomain is established at construction', () => {
        const q = new CommandQueue();
        q.loadCmds([
            ['setSiteId', 'domain-site'],
            ['setCookieDomain', 'declared.example'],
        ]);
        q.process();

        expect(window.OWATracker.getOption('cookie_domain')).toBe('.declared.example');
        expect(window.OWATracker.getOption('cookie_domain_set')).toBe(true);
    });
});
