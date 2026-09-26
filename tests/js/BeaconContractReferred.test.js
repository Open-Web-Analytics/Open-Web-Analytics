/**
 * @jest-environment jsdom
 * @jest-environment-options {"url": "https://example.com/p", "referrer": "https://referrer.example.net/landing"}
 *
 * Beacon contract test for the two REFERRED new-session pageview shapes — the
 * companion to BeaconContract.test.js (which covers direct/no-referrer beacons).
 *
 * A first pageview that has traffic attribution comes in one of two mutually
 * exclusive wire shapes, and the tracker's attribution logic picks exactly one:
 *
 *  - REFERRAL: the visit has a document.referrer but no owa_* campaign params.
 *    The tracker infers attribution from the referrer and puts `session_referer`
 *    on the beacon; the server later derives source/medium/referer_id from it.
 *
 *  - CAMPAIGN: the visit carries owa_* campaign params on the URL. The campaign
 *    attribution path fires INSTEAD of referrer inference, so the beacon carries
 *    `campaign`/`source`/`medium`/`search_terms`/`attribs` and NOT
 *    `session_referer`.
 *
 * This locks both shapes into tests/fixtures/beacon_contracts.json
 * (base.page_request.referral / base.page_request.campaign) so the PHP
 * DimensionIngestionTest can assert every dimension-driving field it feeds a
 * handler is one the tracker really emits — the anti-drift guarantee now covers
 * the referer/source/search/campaign dimensions, not just document/ua.
 *
 * The environment (url + referrer) is fixed per file via the docblock above.
 * We toggle the campaign params at runtime with history.replaceState (which
 * updates location.href, the source parseUrlParams reads) and reset the OWA
 * singleton's state + cookies between captures so the two shapes are
 * independent regardless of order.
 */
import fs from 'fs';
import path from 'path';
import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';
import { StateManager } from '../../modules/Base/src/common/StateManager.js';

const CONTRACTS = JSON.parse(
    fs.readFileSync(path.join(__dirname, '../fixtures/beacon_contracts.json'), 'utf8')
);

/**
 * Wipe cookies and give the OWA singleton a fresh state manager so a prior
 * capture's session/campaign state can't leak into the next one.
 */
function resetOwaState() {
    document.cookie.split(';').forEach((c) => {
        const name = c.split('=')[0].trim();
        if (name) {
            document.cookie = name + '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=/';
        }
    });
    OWA.state = new StateManager();
}

/**
 * Fire a fresh-state tracker at the current location and return the sorted list
 * of property names it put on the wire.
 */
function emittedKeys(fire) {
    resetOwaState();
    const t = new OWATracker({ cookie_domain_set: true });
    t.setSiteId('contract-site');
    let beacon = null;
    t.logEvent = (properties) => { beacon = properties; };
    fire(t);
    if (!beacon) {
        throw new Error('tracker did not emit a beacon');
    }
    return Object.keys(beacon).sort();
}

/**
 * As emittedKeys(), but the whole beacon -- for the cases where the VALUE is
 * the thing under test and a key's presence would not catch it being wrong.
 */
function emittedValues(fire) {
    resetOwaState();
    const t = new OWATracker({ cookie_domain_set: true });
    t.setSiteId('contract-site');
    let beacon = null;
    t.logEvent = (properties) => { beacon = properties; };
    fire(t);
    if (!beacon) {
        throw new Error('tracker did not emit a beacon');
    }
    return beacon;
}

describe('tracker referred pageview beacon contracts', () => {
    test('referral (referrer, no campaign) emits its contracted property set', () => {
        // No campaign params on the URL -> referrer inference -> session_referer.
        window.history.replaceState({}, '', '/p');
        const actual = emittedKeys((t) => t.trackPageView(location.href));

        const expected = CONTRACTS['2']['page_view.referral'];
        expect(expected).toBeDefined();
        expect(actual).toEqual(expected.slice().sort());
        // Guard the mutually-exclusive invariant explicitly.
        // The referrer rides as HTTP_REFERER; the session-scoped copy is gone.
        expect(actual).toContain('HTTP_REFERER');
        expect(actual).not.toContain('session_referer');
        expect(actual).not.toContain('tagged_campaign');
    });

    test('campaign (owa_* URL params) emits its contracted property set', () => {
        // Campaign params present: the beacon carries the tags AND the referrer.
        window.history.replaceState(
            {}, '',
            '/p?owa_campaign=summer&owa_source=news&owa_medium=email&owa_search_terms=blue widgets'
        );
        const actual = emittedKeys((t) => t.trackPageView(location.href));

        const expected = CONTRACTS['2']['page_view.campaign'];
        expect(expected).toBeDefined();
        expect(actual).toEqual(expected.slice().sort());
        // The tracker no longer reports the tags it read off the URL. It
        // reports the URL, and the server parses it -- so the evidence is on
        // the wire instead of one page load's reading of it.
        expect(actual).not.toContain('tagged_campaign');
        expect(actual).not.toContain('tagged_source');
        expect(actual).not.toContain('tagged_terms');

        /*
         * AND NO landing_url EITHER, which this asserted until the field came
         * off the wire.
         *
         * The tags are still parsed by the server, but out of page_location on
         * the session-starting beacon -- which is the same URL landing_url held,
         * because both came from getCurrentUrl(). Re-sending it from session
         * state for the life of the session bought nothing.
         */
        expect(actual).not.toContain('landing_url');
        expect(actual).not.toContain('session_referer');

        // The evidence the server parses: the tags are ON page_location, not
        // stripped from it. This is the assertion that would catch the value
        // being collected from the wrong place, which a key's presence alone
        // would not.
        const sent = emittedValues((t) => t.trackPageView(location.href));
        expect(sent.page_location).toContain('owa_campaign=summer');
        expect(sent.page_location).toContain('owa_source=news');
        expect(sent.page_location).toContain('owa_search_terms=blue');

        /*
         * The referrer still rides every beacon as HTTP_REFERER, which is what
         * the server classifies from -- the SESSION's referrer is the
         * referer_host of its first row, read by the pass through the window it
         * already opens for the landing page. A session-scoped copy on every
         * beacon was the thing being paid for twice.
         */
        expect(actual).toContain('HTTP_REFERER');
    });
});
