import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';

/**
 * Event names are stored case-blind, so a case variant of a reserved name is
 * refused like the name itself.
 */

beforeEach(() => {
    OWA.setSetting('loggerPause', false);
});

function newTracker() {
    const t = new OWATracker({ cookie_domain_set: true, cookie_domain: '.case.example' });
    t.setSiteId('case-site');
    const sent = [];
    t.logEvent = (p) => { sent.push(p); };
    return { t, sent };
}

test.each(['Page_View', 'Purchase', 'SCROLL', 'Click', 'First_Visit'])(
    'a case variant of a built-in name is refused: %s', (name) => {

    expect(OWATracker.isLegalCustomEventName(name)).toBe(false);

    const { t, sent } = newTracker();
    t.trackCustomEvent(name, { eps_label: 'x' });

    expect(sent).toHaveLength(0);
});

test('a case variant of the owa_ prefix is refused', () => {
    expect(OWATracker.isLegalCustomEventName('OWA_thing')).toBe(false);
    expect(OWATracker.isLegalCustomEventName('Owa_thing')).toBe(false);
});

test('a case variant of a plugin-reserved name is refused', () => {
    OWATracker.registerPlugin({ name: 'casing', reservedEventNames: ['casing_frame'] });

    expect(OWATracker.isLegalCustomEventName('Casing_Frame')).toBe(false);
});

test('an ordinary mixed-case name is still accepted and sent as written', () => {
    expect(OWATracker.isLegalCustomEventName('Video_Play')).toBe(true);

    const { t, sent } = newTracker();
    t.trackCustomEvent('Video_Play', {});

    expect(sent).toHaveLength(1);
    expect(sent[0].event_type).toBe('Video_Play');
});
