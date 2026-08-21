import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';

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
describe('automation reporting', () => {

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
    });

    test('reports 1 when the browser says it is being driven', () => {
        setWebdriver(true);
        expect(new OWATracker().isAutomatedBrowser()).toBe(1);
    });

    test('reports 0 for an ordinary browser', () => {
        setWebdriver(false);
        expect(new OWATracker().isAutomatedBrowser()).toBe(0);
    });

    test('reports 0 when the flag is absent entirely', () => {
        delete navigator.webdriver;
        expect(new OWATracker().isAutomatedBrowser()).toBe(0);
    });

    /**
     * Only the standard flag. If someone adds plugin-count or language-list
     * heuristics later, this fails -- which is the point, given a false
     * positive silently costs a real page view.
     */
    test('does not guess from anything other than the standard flag', () => {
        setWebdriver(false);
        Object.defineProperty(navigator, 'plugins', { value: [], configurable: true });
        Object.defineProperty(navigator, 'languages', { value: [], configurable: true });

        expect(new OWATracker().isAutomatedBrowser()).toBe(0);
    });

    /**
     * The parameter is absent for ordinary browsers rather than sent as zero.
     * The server defaults it to 0, so sending it would add a parameter to every
     * beacon to restate the default -- and the beacon contract tests assert the
     * exact property set, which is what caught this.
     */
    test('the beacon carries no automation parameter for an ordinary browser', () => {
        setWebdriver(false);

        const tracker = new OWATracker();
        const event = { props: {}, get(k) { return this.props[k]; }, set(k, v) { this.props[k] = v; } };

        tracker.addDefaultsToEvent(event);

        expect(Object.keys(event.props)).not.toContain('is_automated');
    });

    test('the beacon carries it when the browser is under automation', () => {
        setWebdriver(true);

        const tracker = new OWATracker();
        const event = { props: {}, get(k) { return this.props[k]; }, set(k, v) { this.props[k] = v; } };

        tracker.addDefaultsToEvent(event);

        expect(event.props.is_automated).toBe(1);
    });
});
