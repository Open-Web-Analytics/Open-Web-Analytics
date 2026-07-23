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
import { OWATracker } from '../../modules/base/src/tracker/Tracker.js';
import { OWA_instance as OWA } from '../../modules/base/src/common/owa.js';
import { StateManager } from '../../modules/base/src/common/StateManager.js';

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

describe('tracker referred pageview beacon contracts', () => {
    test('referral (referrer, no campaign) emits its contracted property set', () => {
        // No campaign params on the URL -> referrer inference -> session_referer.
        window.history.replaceState({}, '', '/p');
        const actual = emittedKeys((t) => t.trackPageView(location.href));

        const expected = CONTRACTS['base.page_request.referral'];
        expect(expected).toBeDefined();
        expect(actual).toEqual(expected.slice().sort());
        // Guard the mutually-exclusive invariant explicitly.
        expect(actual).toContain('session_referer');
        expect(actual).not.toContain('campaign');
    });

    test('campaign (owa_* URL params) emits its contracted property set', () => {
        // Campaign params present -> campaign attribution -> no session_referer.
        window.history.replaceState(
            {}, '',
            '/p?owa_campaign=summer&owa_source=news&owa_medium=email&owa_search_terms=blue widgets'
        );
        const actual = emittedKeys((t) => t.trackPageView(location.href));

        const expected = CONTRACTS['base.page_request.campaign'];
        expect(expected).toBeDefined();
        expect(actual).toEqual(expected.slice().sort());
        // Campaign attribution suppresses referrer inference.
        expect(actual).toContain('campaign');
        expect(actual).toContain('source');
        expect(actual).toContain('search_terms');
        expect(actual).not.toContain('session_referer');
    });
});
