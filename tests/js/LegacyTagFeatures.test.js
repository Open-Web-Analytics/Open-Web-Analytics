import { CommandQueue } from '../../modules/Base/src/tracker/CommandQueue.js';
import { isLegacyTrackerSrc, featuresUrl, loadProfileFeatures } from '../../modules/Base/src/tracker/LegacyTag.js';

/**
 * A 1.x tag gets its Profile's behaviour features without pushing a command
 * for each (LegacyTag.js), and a page turns any feature off with
 * disableFeature -- under a legacy tag or a bundle alike.
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

describe('which tags are legacy', () => {

    test('the two paths 1.x tags hardcode are legacy', () => {
        expect(isLegacyTrackerSrc('https://owa.example.test/modules/base/js/owa.tracker-combined-min.js')).toBe(true);
        expect(isLegacyTrackerSrc('https://owa.example.test/owa/modules/base/dist/owa.tracker.js')).toBe(true);
        expect(isLegacyTrackerSrc('https://owa.example.test/modules/base/js/owa.tracker-combined-min.js?v=1')).toBe(true);
    });

    test('v2 tags are not: the classic tag, a bundle, nothing at all', () => {
        expect(isLegacyTrackerSrc('https://owa.example.test/public/base/dist/owa.tracker.js')).toBe(false);
        expect(isLegacyTrackerSrc('https://owa.example.test/public/tracker/abc123.js')).toBe(false);
        expect(isLegacyTrackerSrc('')).toBe(false);
        expect(isLegacyTrackerSrc(undefined)).toBe(false);
    });
});

describe('loading the Profile features', () => {

    test('the features file is the Profile\'s, beside its bundle', () => {
        expect(featuresUrl('https://owa.example.test/', 'abc123'))
            .toBe('https://owa.example.test/public/tracker/features/abc123.js');
        expect(featuresUrl('https://owa.example.test', 'a b'))
            .toBe('https://owa.example.test/public/tracker/features/a%20b.js');
    });

    test('it is requested once, and not without a site id', () => {
        expect(loadProfileFeatures(document, window.owa_baseUrl, '')).toBe(false);

        expect(loadProfileFeatures(document, window.owa_baseUrl, 'abc123')).toBe(true);
        expect(loadProfileFeatures(document, window.owa_baseUrl, 'abc123')).toBe(false);

        const scripts = document.querySelectorAll('script[data-owa-features]');
        expect(scripts).toHaveLength(1);
        expect(scripts[0].src).toBe('https://owa.example.test/public/tracker/features/abc123.js');
        expect(scripts[0].async).toBe(true);
    });
});

describe('disableFeature', () => {

    test('a feature the page disabled is skipped when it arrives', () => {
        const q = new CommandQueue();
        q.push(['setSiteId', 'abc123']);
        q.push(['disableFeature', 'trackScroll']);

        const scroll = jest.spyOn(window.OWATracker, 'trackScroll');
        const forms  = jest.spyOn(window.OWATracker, 'trackForms').mockImplementation(() => {});

        // What the features file (or a bundle's tail) pushes afterwards.
        q.push(['trackScroll']);
        q.push(['trackForms']);

        expect(scroll).not.toHaveBeenCalled();
        expect(forms).toHaveBeenCalledTimes(1);
        expect(window.OWATracker.isScrollTrackingEnabled).toBe(false);
    });

    test('disabling twice records it once; other commands are untouched', () => {
        const q = new CommandQueue();
        q.push(['setSiteId', 'abc123']);
        q.push(['disableFeature', 'trackForms']);
        q.push(['disableFeature', 'trackForms']);

        expect(window.OWATracker.disabledFeatures).toEqual(['trackForms']);
        expect(window.OWATracker.isFeatureDisabled('trackScroll')).toBe(false);
    });
});
