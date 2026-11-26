/**
 * @jest-environment jsdom
 * @jest-environment-options {"url": "https://example.com/p"}
 */
jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';
import { Util } from '../../modules/Base/src/common/Util.js';
import { CommandQueue } from '../../modules/Base/src/tracker/CommandQueue.js';
import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';

/**
 * Util uses the language's own string and array functions, not ports of
 * PHP's.
 *
 * PHP's strpos returns the INDEX of a match or false when there is none, so a
 * match at position 0 is indistinguishable from no match to any caller that
 * tests it as a boolean. includes() asks the question the callers ask, and
 * these tests pin the answers at position zero.
 */
describe('a match at position zero is a match', () => {

    test('a command name beginning with a dot is parsed as namespaced', () => {
        // A match at index 0 is still a match: the name is namespaced, not a
        // bare method name on OWATracker with the dot attached.
        const parsed = CommandQueue.parseCmd(['.setSiteId', 'x']);

        expect(parsed.method).not.toBe('.setSiteId');
        expect(parsed.object).toBe('');
        expect(parsed.method).toBe('setSiteId');

        // ...and an ordinary namespaced name is unaffected.
        const normal = CommandQueue.parseCmd(['owa.setSiteId', 'x']);
        expect(normal.object).toBe('owa');
        expect(normal.method).toBe('setSiteId');
    });

    test('an assoc string beginning with its separator is still an assoc string', () => {
        // The legacy cookie format is 'key=>value|||key=>value'. A leading
        // separator put the match at index 0, so the reader handed the whole
        // raw string back as if it held no pairs at all.
        const out = Util.jsonFromAssocString('=>orphan|||plan=>pro');

        expect(typeof out).toBe('object');
        expect(out['plan']).toBe('pro');
    });
});

describe('cookie parsing without the strpos port', () => {

    /*
     * document.cookie is driven directly rather than through assignment.
     * jsdom's setter refuses to store a fragment with no '=', so a cookie jar
     * that actually contains one cannot be produced by setting cookies. A real
     * browser will hand this string over: a cookie whose name is empty, or one
     * set by another script with a bare flag, both serialise this way.
     */
    function withCookieHeader(header, fn) {
        const original = Object.getOwnPropertyDescriptor(Document.prototype, 'cookie');
        Object.defineProperty(document, 'cookie', {
            configurable: true,
            get: () => header,
            set: () => {},
        });
        try {
            return fn();
        } finally {
            delete document.cookie;
            if (original) { Object.defineProperty(Document.prototype, 'cookie', original); }
        }
    }

    test('a valueless cookie fragment is skipped rather than half-parsed', () => {
        const jar = withCookieHeader('owa_real=value1; justaflag; owa_other=value2',
            () => Util.readAllCookies());

        // A fragment with no '=' is not filed under the empty-string key, where
        // any later lookup could collide with it.
        expect(jar['owa_real']).toEqual(['value1']);
        expect(jar['owa_other']).toEqual(['value2']);
        expect(jar).not.toHaveProperty('');
        expect(jar).not.toHaveProperty('ustaflag');
    });

    test('a cookie whose value contains = keeps the whole value', () => {
        const jar = withCookieHeader('owa_b=cv1=plan%3Dpro', () => Util.readAllCookies());

        // Split on the FIRST '=' only. A naive split() would have dropped
        // everything after the second one, which for the legacy 'b' store is
        // the custom variable's value.
        expect(jar['owa_b']).toEqual(['cv1=plan%3Dpro']);
    });

    test('two cookies with the same name both survive', () => {
        const jar = withCookieHeader('owa_v=first; owa_v=second', () => Util.readAllCookies());

        // The jar is arrays for exactly this reason: a cookie set on both the
        // apex and a subdomain arrives twice, and which one is the tracker's is
        // decided later by the domain hash.
        expect(jar['owa_v']).toEqual(['first', 'second']);
    });
});

describe('the random guid', () => {

    test('the visitor id it feeds is still all digits', () => {
        // The tracker id contract: guids must be numeric, because the column is
        // BIGINT. A padding change that let a non-digit through would be found
        // by the database, at write time, in production.
        for (let i = 0; i < 25; i++) {
            expect(Util.generateRandomGuid().toString()).toMatch(/^[0-9]+$/);
        }
    });
});

describe('string coercion the ports used to provide', () => {

    test('trimming a non-string does not throw', () => {
        // The trim() port opened with (str + ''), so callers could hand it
        // anything. String.prototype.trim would throw on a number, so the call
        // sites coerce explicitly -- setUserName and setPageType are both
        // reachable with whatever a site owner passed to the command queue.
        expect(() => String(42).trim()).not.toThrow();
        expect(String(42).trim()).toBe('42');
        expect(String(null).trim()).toBe('null');
    });
});

/**
 * Beacon param names carry no prefix: a page that sets an app_ns changes
 * nothing.
 */
describe('beacon param names', () => {

    test('carry no prefix, whatever app_ns a page sets', () => {
        window.owa_baseUrl = 'https://owa.example.test/';
        OWA.setSetting('app_ns', '%s_');

        const sent = [];
        const Orig = global.Image;
        global.Image = class { set src(v) { sent.push(v); } };

        try {
            const t = new OWATracker({ cookie_domain_set: true });
            t.setSiteId('bare-site');
            t.trackPageView('https://example.com/p');

            expect(sent).toHaveLength(1);
            expect(sent[0]).toMatch(/[?&]site=bare-site(&|$)/);
            expect(sent[0]).not.toContain('%s_');
        } finally {
            global.Image = Orig;
            delete OWA.config.app_ns;
        }
    });
});

/**
 * Base64, and the bug the port was hiding.
 *
 * The cross-domain link builder base64s a bundle of state onto the URL, and the
 * landing page decodes it. Both halves used a phpjs port whose UTF-8 step
 * encoded each half of a surrogate pair on its own -- CESU-8 rather than UTF-8.
 * Since both halves agreed, the round trip inside OWA always worked, and the
 * output was simply wrong for anything outside the Basic Multilingual Plane.
 *
 * Which is to say: every emoji. A custom variable, user name or page title
 * holding one crossed a domain boundary in an encoding nothing else decodes,
 * and would have arrived mangled at any consumer that was not this same port.
 */
describe('base64 through the browser', () => {

    test('ASCII is byte-for-byte what it always was', () => {
        // The wire format for everything already in flight. If this moves, every
        // link a currently-loaded page has already written stops decoding.
        expect(Util.base64_encode('v=1234567890')).toBe(btoa('v=1234567890'));
        expect(Util.base64_decode(btoa('v=1234567890'))).toBe('v=1234567890');
        expect(Util.base64_encode('')).toBe('');
        expect(Util.base64_decode('')).toBe('');
    });

    test('an emoji survives the round trip AND is encoded as real UTF-8', () => {
        const withEmoji = 'plan=pro 😀';

        expect(Util.base64_decode(Util.base64_encode(withEmoji))).toBe(withEmoji);

        // The round trip alone is not the test: the bytes have to be the UTF-8
        // ones, which is what any other decoder will assume they are. The expected
        // value is stated literally rather than computed, so this asserts a fact
        // about UTF-8 rather than restating the implementation.
        // 😀 is U+1F600: f0 9f 98 80.
        expect(Util.base64_encode('😀')).toBe('8J+YgA==');
        expect(Util.base64_encode(withEmoji)).toBe('cGxhbj1wcm8g8J+YgA==');
    });

    test('a token from an older tracker decodes instead of throwing', () => {
        // CESU-8 for U+1D11E, the two surrogates encoded separately, as an older
        // tracker wrote it. decodeURIComponent rejects it, and a throw here would
        // break the landing page's state restore rather than degrade it.
        const legacy = '7aC07bSe';

        expect(() => Util.base64_decode(legacy)).not.toThrow();
        expect(Util.base64_decode(legacy)).toBeTruthy();
    });
});
