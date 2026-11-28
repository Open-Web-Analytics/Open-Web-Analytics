import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';

/**
 * Traffic attribution, and the tracker's part in it.
 *
 * The tracker attributes nothing. It sends the page's URL and its referrer on
 * every beacon, and the server decides source, medium and campaign: it reads
 * the campaign tags out of the landing page's URL
 * (TrackingEventHelpers::parseLandingTags(), the parameter names from the
 * `campaignKeys` setting, tested in LandingUrlCampaignParseTest) and the
 * referrer.
 *
 * What is tested here is that the referrer reaches the server on every
 * beacon, whether or not the landing URL carries campaign tags. The URL is set
 * with history.replaceState, which updates document.URL.
 */

function setDocumentDomain(domain) {
    Object.defineProperty(document, 'domain', {
        configurable: true,
        get() { return domain; },
    });
}

function setUrl(pathAndQuery) {
    window.history.replaceState({}, '', pathAndQuery);
}

function newTracker(options) {
    const t = new OWATracker(Object.assign(
        { cookie_domain_set: true, cookie_domain: '.cv.example' },
        options || {}
    ));
    t.setSiteId('attribution-site');
    return t;
}

beforeEach(() => {
    setDocumentDomain('cv.example');
    OWA.setSetting('cookie_domain', '.cv.example');
    OWA.setSetting('hashCookiesToDomain', false);
    OWA.setSetting('loggerPause', false);
    ['v', 's', 'c', 'b'].forEach((store) => OWA.clearState(store));
});

afterEach(() => {
    ['v', 's', 'c', 'b'].forEach((store) => OWA.clearState(store));
    window.history.replaceState({}, '', '/');
});

/*
 * The tracker attributes nothing: it sends landing_url, and taggedColumns()
 * parses the tags out of it server-side. What is tested here is the URL
 * parse, and the session referrer, which does ride the beacon.
 */




describe('the referrer reaches the server on every beacon, campaign or not', () => {

    function withReferrer(value) {
        Object.defineProperty(document, 'referrer', {
            value: value, configurable: true
        });
    }

    afterEach(() => { withReferrer(''); });

    /*
     * No campaign gate, anywhere, between the referrer and the server: the
     * server needs the referrer in its own right for is_searchengine and the
     * referring-sites report. The referrer is per-event evidence --
     * HTTP_REFERER, on every beacon, from that page's own document.referrer --
     * and the SESSION's referrer is the referer_host of its first row.
     */
    test('a campaign-tagged landing page still sends its referrer', () => {

        withReferrer('https://partner.example/post');
        setUrl('/landing?owa_campaign=spring&owa_medium=email');

        const t = newTracker();

        expect(t.collectPageProperties()['HTTP_REFERER'])
            .toBe('https://partner.example/post');
    });

    test('an untagged landing page sends it too', () => {

        withReferrer('https://news.example/article');
        setUrl('/landing');

        const t = newTracker();

        expect(t.collectPageProperties()['HTTP_REFERER'])
            .toBe('https://news.example/article');
    });

    /*
     * And a later page in the same session sends ITS OWN referrer, which is one
     * of this site's own pages. Each beacon reports what the browser told it,
     * and the server takes the session's referrer from the first row.
     */
    test('a later page in the same session sends its own referrer', () => {

        withReferrer('https://news.example/article');
        setUrl('/landing');

        const t = newTracker();

        expect(t.collectPageProperties()['HTTP_REFERER'])
            .toBe('https://news.example/article');

        withReferrer('https://cv.example/landing');
        setUrl('/second');

        expect(t.collectPageProperties()['HTTP_REFERER'])
            .toBe('https://cv.example/landing');
    });
});
