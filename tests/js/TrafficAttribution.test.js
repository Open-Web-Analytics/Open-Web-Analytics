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
    OWA.setSetting('ns', 'owa_');
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
 * THE ATTRIBUTION MODELS ARE GONE and their cases with them. The client used
 * to load a campaign stack from the `c` cookie, run last-touch or first-touch
 * over the URL's tags and write the stack back -- and none of it reached the
 * server. No tracker generation put the tags on the wire: v1 and v2 both send
 * landing_url and taggedColumns() parses them out of it server-side.
 *
 * What is still tested here is what still happens: the URL parse, and the
 * session referrer, which does ride the beacon.
 */
/*
 * THE TAG PARSE IS NOT THE TRACKER'S ANY MORE, so its four cases are gone --
 * extraction off the URL, the ad/ad_type backfill, and the remapped public key.
 *
 * The tracker sends landing_url and the server parses the tags out of it in
 * TrackingEventHelpers::parseLandingTags(), with the parameter names coming
 * from the per-Property `campaignKeys` setting. The remapping case is the one
 * worth following: it asserted that setCampaignSourceKey('utm_source') was
 * honoured, and it WAS honoured here while doing nothing end to end, because
 * the server built its own ns-prefixed list and never read the tracker's. A
 * site using utm_* got no attribution and this test said it was fine.
 *
 * It is a real capability now, tested where it takes effect:
 * LandingUrlCampaignParseTest.
 */




describe('the referrer reaches the server on every beacon, campaign or not', () => {

    function withReferrer(value) {
        Object.defineProperty(document, 'referrer', {
            value: value, configurable: true
        });
    }

    afterEach(() => { withReferrer(''); });

    /*
     * WHAT THIS USED TO TEST, and why the subject changed.
     *
     * The regression it was written for: a landing page carrying campaign tags
     * recorded NO referrer, because the write sat in the `else` of
     * `if (isTrafficAttributed)`. Right while the browser decided attribution --
     * campaign beat referrer -- and wrong once the server resolved, because the
     * server needs the referrer in its own right for is_searchengine and the
     * referring-sites report.
     *
     * The mechanism it tested is gone: the tracker no longer writes a
     * session-scoped `referer` into state, and session_referer is off the wire.
     * The referrer is per-event evidence now -- HTTP_REFERER, on every beacon,
     * from that page's own document.referrer -- and the SESSION's referrer is
     * the referer_host of its first row, which the pass reads through the window
     * it already opens for the landing page.
     *
     * So what survives is the claim that matters: no campaign gate, anywhere,
     * between the referrer and the server.
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
     * of this site's own pages.
     *
     * That used to be forbidden -- session_referer was declared `scope:
     * 'session'` and had to be identical on every event sharing a session_id, so
     * writing it again mid-session broke the scope contract. With the field gone
     * there is no session-scoped value to violate: each beacon reports what the
     * browser told it, and the server takes the session's referrer from the first
     * row. The internal referrer on later beacons is exactly the evidence that
     * makes "first row" the right rule.
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
