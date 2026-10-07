import { CommandQueue } from '../../modules/Base/src/tracker/CommandQueue.js';

/**
 * The tracker starts its features option by itself once the queued commands
 * have run; a page turns one off with the disabledFeatures option, under a
 * manual tag or a bundle tag alike.
 */

beforeEach(() => {
    window.owa_baseUrl = 'https://owa.example.test/';
    Object.defineProperty(document, 'domain', { configurable: true, get() { return 'site.example'; } });
    document.head.innerHTML = '';
});

afterEach(() => {
    delete window.owa_baseUrl;
    delete window.OWATracker;
});

describe('the disabledFeatures option', () => {

    test('a feature the page disabled is skipped when it arrives', () => {
        const q = new CommandQueue();
        q.push(['setSiteId', 'abc123']);
        q.push(['setOption', 'disabledFeatures', ['trackScroll', 'trackSiteSearch']]);

        const scroll = jest.spyOn(window.OWATracker, 'trackScroll');
        const search = jest.spyOn(window.OWATracker, 'trackSiteSearch');
        const forms  = jest.spyOn(window.OWATracker, 'trackForms').mockImplementation(() => {});

        // What a bundle's tail pushes afterwards.
        q.push(['trackScroll']);
        q.push(['trackSiteSearch']);
        q.push(['trackForms']);

        expect(scroll).not.toHaveBeenCalled();
        expect(search).not.toHaveBeenCalled();
        expect(forms).toHaveBeenCalledTimes(1);
        expect(window.OWATracker.isScrollTrackingEnabled).toBe(false);
    });

    test('without the option, or with something other than a list, nothing is skipped', () => {
        const q = new CommandQueue();
        q.push(['setSiteId', 'abc123']);

        expect(window.OWATracker.isFeatureDisabled('trackScroll')).toBe(false);

        q.push(['setOption', 'disabledFeatures', 'trackScroll']);
        expect(window.OWATracker.isFeatureDisabled('trackScroll')).toBe(false);
    });
});

describe('the features the tracker starts by itself', () => {

    const ALL = ['trackClicks', 'trackForms', 'trackScroll', 'trackSiteSearch', 'trackExceptions', 'trackRouteChanges'];

    function spies() {
        const out = {};
        for (const name of ALL) {
            out[name] = jest.spyOn(window.OWATracker, name).mockImplementation(() => {});
        }
        return out;
    }

    test('every feature but Domstream and route changes starts once the queued commands have run', () => {
        const q = new CommandQueue();
        q.push(['setSiteId', 'abc123']);
        const s = spies();
        const domstream = typeof window.OWATracker.trackDomStream === 'function'
            ? jest.spyOn(window.OWATracker, 'trackDomStream') : null;

        window.OWATracker.startFeatures();

        for (const name of ALL.filter((n) => n !== 'trackRouteChanges')) {
            expect(s[name]).toHaveBeenCalledTimes(1);
        }
        // A single-page app that sends its own page view per route would count each twice.
        expect(s.trackRouteChanges).not.toHaveBeenCalled();
        if (domstream) {
            expect(domstream).not.toHaveBeenCalled();
        }
    });

    test('a feature the page disabled does not start', () => {
        const q = new CommandQueue();
        q.push(['setSiteId', 'abc123']);
        q.push(['setOption', 'disabledFeatures', ['trackScroll', 'trackExceptions']]);
        const s = spies();

        window.OWATracker.startFeatures();

        expect(s.trackScroll).not.toHaveBeenCalled();
        expect(s.trackExceptions).not.toHaveBeenCalled();
        expect(s.trackForms).toHaveBeenCalledTimes(1);
    });

    test('a feature the page already ran is not run again: a second site search is a second event', () => {
        const q = new CommandQueue();
        q.push(['setSiteId', 'abc123']);
        const s = spies();
        q.push(['trackSiteSearch']);

        window.OWATracker.startFeatures();
        window.OWATracker.startFeatures();

        expect(s.trackSiteSearch).toHaveBeenCalledTimes(1);
        expect(s.trackForms).toHaveBeenCalledTimes(1);
    });

    test('a page, or its Profile\'s bundle, replaces the list with setOption', () => {
        const q = new CommandQueue();
        q.push(['setSiteId', 'abc123']);
        q.push(['setOption', 'features', ['trackForms']]);
        const s = spies();

        window.OWATracker.startFeatures();

        expect(s.trackForms).toHaveBeenCalledTimes(1);
        expect(s.trackScroll).not.toHaveBeenCalled();
        expect(s.trackClicks).not.toHaveBeenCalled();
    });

    test('the option defaults are defaults.json, which the Profile settings read too', () => {
        const DEFAULTS = require('../../modules/Base/src/tracker/defaults.json');
        const q = new CommandQueue();
        q.push(['setSiteId', 'abc123']);

        expect(window.OWATracker.getOption('features')).toEqual(DEFAULTS.features);
        expect(window.OWATracker.getOption('downloadExtensions')).toEqual(DEFAULTS.downloadExtensions);
        expect(window.OWATracker.getOption('scrollThresholds')).toEqual(DEFAULTS.scrollThresholds);
        expect(window.OWATracker.getOption('siteSearchParams')).toEqual(DEFAULTS.siteSearchParams);
        expect(DEFAULTS.features).not.toContain('trackDomStream');
        expect(DEFAULTS.features).not.toContain('trackPageView');
        expect(DEFAULTS.features).not.toContain('trackRouteChanges');
    });
});
