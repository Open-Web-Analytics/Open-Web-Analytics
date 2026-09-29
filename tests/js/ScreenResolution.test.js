import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';

/**
 * screen_resolution: the device's screen as WIDTHxHEIGHT, on every event.
 */

function newTracker() {
    const t = new OWATracker({ cookie_domain_set: true, cookie_domain: '.sr.example' });
    t.setSiteId('sr-site');
    return t;
}

function screenOf(width, height) {
    Object.defineProperty(window, 'screen', {
        configurable: true,
        get: () => ({ width, height }),
    });
}

beforeEach(() => {
    OWA.setSetting('ns', 'owa_');
    OWA.setSetting('loggerPause', false);
});

test('the screen is WIDTHxHEIGHT', () => {
    screenOf(1920, 1080);

    expect(newTracker().getScreenResolution()).toBe('1920x1080');
});

test('a fractional size is rounded, as a zoomed screen reports one', () => {
    screenOf(1536.4, 864.2);

    expect(newTracker().getScreenResolution()).toBe('1536x864');
});

test('no screen, or a zero one, sends nothing rather than 0x0', () => {
    screenOf(0, 0);

    const t = newTracker();
    const sent = [];
    t.logEvent = (p) => { sent.push(p); };
    t.trackPageView();

    expect(t.getScreenResolution()).toBe('');
    expect(sent[0]).not.toHaveProperty('screen_resolution');
});

test('it rides every event, not only the page view', () => {
    screenOf(390, 844);

    const t = newTracker();
    const sent = [];
    t.logEvent = (p) => { sent.push(p); };

    t.trackPageView();
    t.trackCustomEvent('newsletter_signup', {});

    expect(sent.map((e) => e.screen_resolution)).toEqual(['390x844', '390x844']);
});
