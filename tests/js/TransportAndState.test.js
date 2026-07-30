import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';
import { Event } from '../../modules/Base/src/tracker/Event.js';

/**
 * Beacon transport + the trackEvent() state orchestration.
 *
 * This is the on-the-wire contract and the pipeline that fills an event before
 * it leaves the browser:
 *
 *   - getLoggerEndpoint(): resolves the log.php URL, preferring an explicit
 *     logger_endpoint option, then the tracker baseUrl.
 *
 *   - prepareRequestData(): turns an event's flat property bag into the owa_*
 *     namespaced request params. Arrays flatten to owa_key[i] and arrays of
 *     objects to owa_key[i][subkey] -- the shape PHP's $_GET bracket parser
 *     reassembles server-side.
 *
 *   - prepareRequestDataForGet(): serializes those params to a query string,
 *     url-encoding the VALUE only (encodeURIComponent) and leaving the KEY raw.
 *     Encoding the value is the symmetric half of the server's decode; leaving
 *     the key raw preserves the owa_foo[0][bar] brackets $_GET relies on. (This
 *     is the fix guarded by the block comment in the source -- a '#'/'&'/'='
 *     in a value would otherwise truncate or corrupt the beacon.)
 *
 *   - logEvent(): the transport switch. Under getRequestCharacterLimit it fires
 *     a GET pixel (new Image().src = url); over the limit it falls back to a
 *     cross-domain POST (cdPost). An inactive tracker sends nothing.
 *
 *   - addDefaultsToEvent(): backfills site_id, page_url, HTTP_REFERER,
 *     page_title, and timestamp only when the event/global doesn't already
 *     carry them.
 *
 *   - addGlobalPropertiesToEvent(): copies custom vars + accumulated global
 *     properties onto the event, but never clobbers a value the event already
 *     set locally.
 *
 *   - trackEvent(): the orchestrator. In first-party mode it runs
 *     manageState -> addGlobalPropertiesToEvent -> addDefaultsToEvent -> logEvent
 *     so a single beacon carries identity + defaults. In thirdParty mode it
 *     skips client state management and just flags the event for upstream.
 *
 * Image is stubbed to capture beacon URLs without hitting the network. jsdom
 * backs the state stores, so trackEvent() exercises the real identity pipeline.
 */

function setDocumentDomain(domain) {
    Object.defineProperty(document, 'domain', {
        configurable: true,
        get() { return domain; },
    });
}

function newTracker(options) {
    const t = new OWATracker(Object.assign(
        { cookie_domain_set: true, cookie_domain: '.cv.example', baseUrl: 'https://track.example/owa/' },
        options || {}
    ));
    t.setSiteId('transport-site');
    return t;
}

let beacons;
let OrigImage;

beforeEach(() => {
    setDocumentDomain('cv.example');
    OWA.setSetting('ns', 'owa_');
    OWA.setSetting('cookie_domain', '.cv.example');
    OWA.setSetting('hashCookiesToDomain', false);
    OWA.setSetting('loggerPause', false);
    ['v', 's', 'c', 'b'].forEach((store) => OWA.clearState(store));

    beacons = [];
    OrigImage = global.Image;
    // Capture the pixel URL the moment .src is assigned.
    global.Image = class { set src(v) { beacons.push(v); } };
});

afterEach(() => {
    global.Image = OrigImage;
    ['v', 's', 'c', 'b'].forEach((store) => OWA.clearState(store));
});

describe('getLoggerEndpoint', () => {

    test('appends log.php to the tracker baseUrl', () => {
        const t = newTracker({ baseUrl: 'https://track.example/owa/' });
        expect(t.getLoggerEndpoint()).toBe('https://track.example/owa/log.php');
    });

    test('prefers an explicit logger_endpoint option', () => {
        const t = newTracker({ logger_endpoint: 'https://cdn.example/x/' });
        expect(t.getLoggerEndpoint()).toBe('https://cdn.example/x/log.php');
    });
});

describe('prepareRequestData: owa_* namespacing and array flattening', () => {

    test('namespaces every param key with the owa_ prefix', () => {
        const t = newTracker();
        const data = t.prepareRequestData({ event_type: 'base.page_request', foo: 'bar' });
        expect(Object.keys(data)).toEqual(['owa_event_type', 'owa_foo']);
    });

    test('flattens arrays to owa_key[i] and arrays of objects to owa_key[i][subkey]', () => {
        const t = newTracker();
        const data = t.prepareRequestData({ arr: ['x', 'y'], objarr: [{ a: 1, b: 2 }] });
        expect(data).toEqual({
            'owa_arr[0]': 'x',
            'owa_arr[1]': 'y',
            'owa_objarr[0][a]': 1,
            'owa_objarr[0][b]': 2,
        });
    });
});

describe('prepareRequestDataForGet: value-only url-encoding', () => {

    test('url-encodes the value (so #, &, = survive) and leaves the key raw', () => {
        const t = newTracker();
        const get = t.prepareRequestDataForGet({ url: 'http://x/?a=1&b=2#frag' });
        // The whole URL value is percent-encoded into a single param; the raw
        // owa_url key is preserved.
        expect(get).toBe('owa_url=http%3A%2F%2Fx%2F%3Fa%3D1%26b%3D2%23frag&');
        // The structural characters must NOT appear un-encoded in the value.
        expect(get.indexOf('#frag')).toBe(-1);
    });
});

describe('logEvent: GET pixel vs POST fallback', () => {

    test('fires a single GET beacon when the url is within the character limit', () => {
        const t = newTracker();
        t.logEvent({ event_type: 'base.page_request', site_id: 'transport-site' });

        expect(beacons.length).toBe(1);
        expect(beacons[0]).toContain('https://track.example/owa/log.php?');
        expect(beacons[0]).toContain('owa_event_type=base.page_request');
    });

    test('falls back to a cross-domain POST when the url exceeds the character limit', () => {
        const t = newTracker();
        t.setOption('getRequestCharacterLimit', 10);
        let posted = null;
        t.cdPost = (data) => { posted = data; };

        t.logEvent({ event_type: 'base.page_request', big: 'x'.repeat(50) });

        // No pixel; the data went out via POST instead.
        expect(beacons.length).toBe(0);
        expect(posted).toBeTruthy();
        expect(posted['owa_event_type']).toBe('base.page_request');
    });

    test('sends nothing while the tracker is inactive', () => {
        const t = newTracker();
        t.active = false;

        t.logEvent({ event_type: 'base.page_request' });

        expect(beacons.length).toBe(0);
    });
});

describe('addDefaultsToEvent', () => {

    test('backfills site_id, page_url, page_title and timestamp', () => {
        const t = newTracker();
        const event = new Event();

        t.addDefaultsToEvent(event, null);
        const p = event.getProperties();

        expect(p.site_id).toBe('transport-site');
        expect(p.page_url).toBeTruthy();
        expect(p.timestamp).toBeTruthy();
        expect(p.hasOwnProperty('page_title')).toBe(true);
    });

    test('does not overwrite a value the event already carries', () => {
        const t = newTracker();
        const event = new Event();
        event.set('page_url', 'http://cv.example/explicit');

        t.addDefaultsToEvent(event, null);

        expect(event.get('page_url')).toBe('http://cv.example/explicit');
    });
});

describe('addGlobalPropertiesToEvent', () => {

    test('copies a global property onto an event that lacks it', () => {
        const t = newTracker();
        t.setGlobalEventProperty('visitor_id', 'vid-1');
        const event = new Event();

        t.addGlobalPropertiesToEvent(event, null);

        expect(event.get('visitor_id')).toBe('vid-1');
    });

    test('never clobbers a value the event set locally', () => {
        const t = newTracker();
        t.setGlobalEventProperty('visitor_id', 'vid-global');
        const event = new Event();
        event.set('visitor_id', 'vid-local-wins');

        t.addGlobalPropertiesToEvent(event, null);

        expect(event.get('visitor_id')).toBe('vid-local-wins');
    });
});

describe('trackEvent: end-to-end orchestration', () => {

    test('emits one beacon carrying identity + core params in first-party mode', () => {
        const t = newTracker();
        const event = new Event();
        event.setEventType('base.page_request');
        event.set('page_url', 'http://cv.example/page');

        t.trackEvent(event);

        expect(beacons.length).toBe(1);
        const url = beacons[0];
        // manageState minted identity; addDefaults stamped site_id; all rode out.
        expect(url).toMatch(/owa_site_id=/);
        expect(url).toMatch(/owa_visitor_id=/);
        expect(url).toMatch(/owa_session_id=/);
        expect(url).toMatch(/owa_event_type=base\.page_request/);
    });

    test('thirdParty mode flags the event for upstream and skips client state management', () => {
        const t = newTracker({ thirdParty: true });
        const event = new Event();
        event.setEventType('base.page_request');

        t.trackEvent(event);

        // Upstream is told to manage state; the client never ran manageState.
        expect(t.globalEventProperties.thirdParty).toBe(true);
        expect(t.stateInit).toBeFalsy();
    });
});
