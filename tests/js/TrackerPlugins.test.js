import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';

/**
 * Code a module compiles into the tracker registers itself: the tracker names
 * no module.
 */

beforeEach(() => {
    OWA.setSetting('ns', 'owa_');
    OWA.setSetting('loggerPause', false);
});

function newTracker() {
    const t = new OWATracker({ cookie_domain_set: true, cookie_domain: '.plugin.example' });
    t.setSiteId('plugin-site');
    return t;
}

test('a plugin adds its methods, reserves its event names and is initialised per tracker', () => {
    const seen = [];

    const registered = OWATracker.registerPlugin({
        name: 'acme',
        reservedEventNames: ['acme_recording'],
        methods: { trackAcme() { return 'acme on ' + this.getSiteId(); } },
        init(tracker) { seen.push(tracker.getSiteId() || 'constructed'); },
    });

    expect(registered).toBe(true);

    const t = newTracker();

    expect(seen).toHaveLength(1);
    expect(t.trackAcme()).toBe('acme on plugin-site');
    expect(OWATracker.RESERVED_EVENT_NAMES).toContain('acme_recording');
    expect(OWATracker.RESERVED_EVENT_NAMES).toContain('page_view');

    const sent = [];
    t.logEvent = (p) => { sent.push(p); };
    t.trackCustomEvent('acme_recording', {});

    expect(sent).toHaveLength(0, 'a site event may not borrow a plugin\'s name');
});

test('a plugin cannot replace a method the tracker already has', () => {
    const original = OWATracker.prototype.trackPageView;

    OWATracker.registerPlugin({ name: 'rogue', methods: { trackPageView() { return 'replaced'; } } });

    expect(OWATracker.prototype.trackPageView).toBe(original);
});

test('a second registration under one name is refused', () => {
    expect(OWATracker.registerPlugin({ name: 'twice' })).toBe(true);
    expect(OWATracker.registerPlugin({ name: 'twice' })).toBe(false);
    expect(OWATracker.registerPlugin({})).toBe(false);
});
