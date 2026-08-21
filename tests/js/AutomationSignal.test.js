import { Util as OwaUtil } from '../../modules/Base/src/common/Util.js';

/**
 * The tracker reports whether it is being driven by automation.
 *
 * The only crawler a JavaScript tracker ever sees is one that runs JavaScript,
 * and those look exactly like Chrome on the server because they ARE Chrome
 * under a script. Measured on a live install, one such crawler made 365
 * requests over two days and was counted as a person.
 *
 * navigator.webdriver is standardised -- a conforming browser must report true
 * while under automation -- so it is the one thing that separates them, and
 * only the client can read it.
 *
 * Deliberately just that flag. A robot is DISCARDED rather than recorded, so a
 * false positive destroys a real page view; plugin counts and language-list
 * heuristics are too guessy for a signal with that cost.
 */
describe('automation is treated as untrackable', () => {

    const original = Object.getOwnPropertyDescriptor(navigator, 'webdriver');

    const setWebdriver = (value) => {
        Object.defineProperty(navigator, 'webdriver', { value, configurable: true });
    };

    afterEach(() => {
        if (original) {
            Object.defineProperty(navigator, 'webdriver', original);
        } else {
            delete navigator.webdriver;
        }
        delete window.owa_track_automated_browsers;
    });

    /**
     * The same predicate Do Not Track uses, because it is the same kind of
     * answer -- the browser saying it should not be tracked. tracker-dom wraps
     * the ENTIRE bootstrap in this, so nothing loads and nothing is sent: no
     * parameter, no request, no decision left to the server.
     */
    test('a driven browser is not trackable', () => {
        setWebdriver(true);
        expect(OwaUtil.isBrowserTrackable()).toBe(false);
    });

    test('an ordinary browser is trackable', () => {
        setWebdriver(false);
        expect(OwaUtil.isBrowserTrackable()).toBe(true);
    });

    test('an absent flag is trackable', () => {
        delete navigator.webdriver;
        expect(OwaUtil.isBrowserTrackable()).toBe(true);
    });

    /**
     * Automating your own site is legitimate -- end to end tests, synthetic
     * monitoring, an uptime check that should show up in reports. Without a way
     * back in, this project's own e2e suite would record nothing, which is a
     * fair warning about what it would do to everyone else's.
     */
    test('automation can be opted back in by the site owner', () => {
        setWebdriver(true);
        window.owa_track_automated_browsers = true;

        expect(OwaUtil.isBrowserTrackable()).toBe(true);
    });

    /**
     * Opt-in, not opt-out: anything other than an explicit true is a crawler.
     */
    test('only an explicit true opts in', () => {
        setWebdriver(true);

        for (const value of ['true', 1, {}, 'yes']) {
            window.owa_track_automated_browsers = value;
            expect(OwaUtil.isBrowserTrackable()).toBe(false);
        }
    });

    /**
     * Do Not Track must keep working, and must not be affected by the opt-in --
     * a person asking not to be tracked outranks a site owner wanting their
     * automation recorded.
     */
    test('do not track still wins, even with automation opted in', () => {
        const dnt = Object.getOwnPropertyDescriptor(navigator, 'doNotTrack');
        Object.defineProperty(navigator, 'doNotTrack', { value: '1', configurable: true });
        window.owa_track_automated_browsers = true;

        expect(OwaUtil.isBrowserTrackable()).toBe(false);

        if (dnt) { Object.defineProperty(navigator, 'doNotTrack', dnt); }
        else { delete navigator.doNotTrack; }
    });
});
