import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';

/**
 * Traffic attribution: campaign extraction + the direct / original models.
 *
 * When a visit lands with campaign params on the URL (owa_source, owa_medium,
 * owa_campaign, owa_search_terms, owa_ad, owa_ad_type by default), the tracker
 * attributes the session to that campaign. The moving parts:
 *
 *   - getCampaignProperties() parses the current URL, pulls the configured
 *     campaign params, and REMAPS each public key (owa_source) to its short
 *     private key (sr). Seeing any campaign param flips isNewCampaign. The ad /
 *     ad_type pair is co-required: if one is present the other is backfilled to
 *     "(not set)".
 *   - The public->private key map is reconfigurable at runtime via
 *     setCampaign{Medium,Name,Source,SearchTerms,Ad,AdType}Key(), so a site can
 *     accept e.g. utm_source instead of owa_source.
 *   - directAttributionModel(): last-touch. Every new campaign touch is appended
 *     to the campaignState touch list (capped at maxPriorCampaigns, oldest
 *     dropped), the campaign cookie ('c') is rewritten, and the session store
 *     ('s') gets the touch's values.
 *   - originalAttributionModel(): first-touch. If a prior touch exists it wins and
 *     the new params are ignored; otherwise the new touch becomes the original.
 *   - setTrafficAttribution() ties it together: loads prior touches from the 'c'
 *     cookie, runs the configured model, promotes the resolved source/medium/etc.
 *     to global event properties (so they ride every event), serializes the touch
 *     list to `attribs`, and -- when NOTHING attributed and it's a new session --
 *     falls back to inferring from document.referrer via `session_referer`.
 *
 * These drive the model methods directly and exercise getCampaignProperties by
 * putting real params on the URL with history.replaceState (which updates
 * document.URL, the source getCampaignProperties parses).
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

describe('getCampaignProperties: extraction and key remapping', () => {

    test('pulls campaign params off the URL and remaps public keys to private keys', () => {
        setUrl('/p?owa_medium=email&owa_campaign=summer&owa_source=news&owa_search_terms=widgets');
        const t = newTracker();

        const params = t.getCampaignProperties();

        // public owa_* keys -> short private keys.
        expect(params).toEqual({
            md: 'email',
            cn: 'summer',
            sr: 'news',
            tr: 'widgets',
        });
        // Seeing any campaign param marks the visit as a new campaign touch.
        expect(t.isNewCampaign).toBe(true);
    });

    test('leaves isNewCampaign false and returns empty when no campaign params present', () => {
        setUrl('/p?unrelated=1');
        const t = newTracker();

        expect(t.getCampaignProperties()).toEqual({});
        expect(t.isNewCampaign).toBe(false);
    });

    test('backfills the co-required ad / ad_type pair with "(not set)"', () => {
        setUrl('/p?owa_ad=banner1');
        const adOnly = newTracker().getCampaignProperties();
        // ad present -> ad_type backfilled.
        expect(adOnly.ad).toBe('banner1');
        expect(adOnly.at).toBe('(not set)');

        setUrl('/p?owa_ad_type=cpc');
        const typeOnly = newTracker().getCampaignProperties();
        // ad_type present -> ad backfilled.
        expect(typeOnly.at).toBe('cpc');
        expect(typeOnly.ad).toBe('(not set)');
    });

    test('honors a remapped public key (e.g. utm_source via setCampaignSourceKey)', () => {
        setUrl('/p?utm_source=google');
        const t = newTracker();
        t.setCampaignSourceKey('utm_source');

        // owa_source would no longer match; utm_source now maps to sr.
        expect(t.getCampaignProperties()).toEqual({ sr: 'google' });
    });
});

describe('directAttributionModel (last-touch)', () => {

    test('appends the new touch, marks attributed, and persists session + cookie state', () => {
        setUrl('/p?owa_source=news&owa_medium=email');
        const t = newTracker();
        const params = t.getCampaignProperties();

        t.directAttributionModel(params);

        expect(t.campaignState.length).toBe(1);
        expect(t.isTrafficAttributed).toBe(true);
        // Session store carries the resolved values under their FULL names.
        expect(OWA.getState('s_attribution-site', 'tagged_source')).toBe('news');
        expect(OWA.getState('s_attribution-site', 'tagged_medium')).toBe('email');
        // The campaign cookie ('c') holds the touch list.
        expect(OWA.getState('c', 'attribs')).toBeTruthy();
    });

    test('caps the touch list at maxPriorCampaigns, dropping the oldest', () => {
        const t = newTracker();
        // Pre-load exactly maxPriorCampaigns (5) touches, then add one more.
        t.campaignState = [{ sr: 'a' }, { sr: 'b' }, { sr: 'c' }, { sr: 'd' }, { sr: 'e' }];
        t.isNewCampaign = true;

        t.directAttributionModel({ sr: 'f' });

        expect(t.campaignState.length).toBe(5);
        // Oldest ('a') dropped; newest ('f') retained.
        expect(t.campaignState[0]).toEqual({ sr: 'b' });
        expect(t.campaignState[t.campaignState.length - 1]).toEqual({ sr: 'f' });
    });

    test('does nothing when the visit is not a new campaign', () => {
        const t = newTracker();
        t.isNewCampaign = false;

        const result = t.directAttributionModel({ sr: 'news' });

        expect(result).toBeUndefined();
        expect(t.campaignState.length).toBe(0);
        expect(t.isTrafficAttributed).toBe(false);
    });
});

describe('originalAttributionModel (first-touch)', () => {

    test('keeps the original touch and ignores the new params when a prior touch exists', () => {
        const t = newTracker();
        t.campaignState = [{ sr: 'first', cn: 'orig' }];
        t.isNewCampaign = true;

        const result = t.originalAttributionModel({ sr: 'second', cn: 'new' });

        // First touch wins.
        expect(result).toEqual({ sr: 'first', cn: 'orig' });
        expect(t.isTrafficAttributed).toBe(true);
        expect(t.campaignState.length).toBe(1);
    });

    test('records the new touch as the original when none exists yet', () => {
        const t = newTracker();
        t.isNewCampaign = true;

        t.originalAttributionModel({ sr: 'brandnew' });

        expect(t.campaignState).toEqual([{ sr: 'brandnew' }]);
        expect(t.isTrafficAttributed).toBe(true);
    });
});

describe('setTrafficAttribution: end to end', () => {

    test('promotes resolved campaign values to global event properties (direct model)', () => {
        setUrl('/p?owa_source=news&owa_medium=email&owa_campaign=summer');
        const t = newTracker({ trafficAttributionMode: 'direct' });
        t.isNewSessionFlag = true;

        t.setTrafficAttribution(null, null);

        // Resolved into the SESSION store, which is where the attribution
        // model writes them and where every event now reads them from.
        expect(OWA.getState('s_attribution-site', 'tagged_source')).toBe('news');
        expect(OWA.getState('s_attribution-site', 'tagged_medium')).toBe('email');
        expect(OWA.getState('s_attribution-site', 'tagged_campaign')).toBe('summer');
        // The serialized touch list rides along as `attribs`.
        expect(JSON.stringify(OWA.getState('c', 'attribs'))).toContain('news');
    });

    test('infers attribution from the referrer when no campaign params and a new session', () => {
        setUrl('/p');
        Object.defineProperty(document, 'referrer', {
            configurable: true,
            get() { return 'https://ref.example/landing'; },
        });
        const t = newTracker();
        t.isNewSessionFlag = true;

        t.setTrafficAttribution(null, null);

        // No campaign -> not attributed -> referrer inference sets session_referer.
        expect(t.isTrafficAttributed).toBe(false);
        expect(OWA.getState('s_attribution-site', 'referer')).toBe('https://ref.example/landing');
    });

    test('runs the callback with the event when provided', () => {
        setUrl('/p?owa_source=news');
        const t = newTracker();
        t.isNewSessionFlag = true;

        const event = { marker: 'evt' };
        let received = null;
        t.setTrafficAttribution(event, (e) => { received = e; });

        expect(received).toBe(event);
    });
});
