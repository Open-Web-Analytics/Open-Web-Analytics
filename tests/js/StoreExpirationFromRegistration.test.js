jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';
import { Util } from '../../modules/Base/src/common/Util.js';

/**
 * A store's cookie lifetime comes from its registration, and from nowhere else.
 *
 * WHAT THIS REPLACED. set() and replaceStore() used to accept an
 * `expiration_days` argument and discard it: persistence reads
 * getExpirationDays(store_name), which is the value given to registerStore().
 * So a caller passing an expiration was silently ignored, and the tracker did
 * exactly that -- it passed `campaignAttributionWindow` on every campaign write,
 * and the documentation told people that setting configured the attribution
 * window. It did not. The window worked only because the campaign store happens
 * to be registered with the same number.
 *
 * The argument is gone. These tests pin the mechanism that was really doing the
 * work, because removing a parameter nobody reads is safe only while the thing
 * that does read something keeps working.
 *
 * A store is backed by one cookie and a cookie has one lifetime, which is why
 * this belongs to the store rather than to a write: two writes asking for
 * different expirations could only fight, last write winning.
 */
describe('store expiration', () => {

    let cookies;

    beforeEach(() => {
        cookies = [];
        jest.spyOn(Util, 'setCookie').mockImplementation((name, value, days) => {
            cookies.push({ name: name, value: value, days: days });
        });
        OWA.setSetting('ns', 'owa_');
        OWA.setSetting('cookie_domain', 'example.com');
    });

    afterEach(() => {
        jest.restoreAllMocks();
    });

    test('the lifetime written is the one given at registration', () => {

        OWA.registerStateStore('expTest', 60, '', 'json');
        OWA.setState('expTest', 'k', 'v');
        OWA.state.persist('expTest', true);

        const written = cookies.filter(c => c.name === 'owa_expTest');

        expect(written.length).toBeGreaterThan(0);
        expect(written[written.length - 1].days).toBe(60);
    });

    test('a different registration gives a different lifetime', () => {

        OWA.registerStateStore('expTest2', 7, '', 'json');
        OWA.setState('expTest2', 'k', 'v');
        OWA.state.persist('expTest2', true);

        const written = cookies.filter(c => c.name === 'owa_expTest2');

        expect(written[written.length - 1].days).toBe(7);
    });

    /**
     * set() takes five arguments. A sixth passed by old code must not become an
     * expiration again by accident -- that is the bug this replaced.
     */
    test('set() does not take an expiration argument', () => {

        expect(OWA.state.set.length).toBe(5);
        expect(OWA.setState.length).toBe(5);
    });
});
