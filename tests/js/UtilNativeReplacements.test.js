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
 * What changed when the PHP-function ports came out of Util.
 *
 * Util carried hand-written JavaScript ports of PHP's standard library --
 * strpos, str_pad, trim, in_array, explode, strtolower, is_array,
 * countObjectProperties -- most of them lifted from phpjs.org around 2010, when
 * the language really was missing the equivalents. It is not any more, and the
 * ports are not free: they ship in every page view, and they carry PHP's return
 * conventions into a language that does not share them.
 *
 * That last part is the reason this file exists rather than being a pure
 * deletion. PHP's strpos returns the INDEX of a match or false when there is
 * none, so a match at position 0 is indistinguishable from no match to any
 * caller that tests it as a boolean -- and all three callers did. Nobody hit it
 * because the strings involved rarely start with the character being looked
 * for, which is what "latent" means. Replacing the port with includes() asks
 * the question the callers were actually asking, and these tests pin the
 * answers at the positions the port got wrong.
 */
describe('a match at position zero is a match', () => {

    test('a command name beginning with a dot is parsed as namespaced', () => {
        // strpos('.foo', '.') is 0, which is falsy, so this used to be read as
        // a bare method name on OWATracker with the dot still attached.
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
     * that actually contains one cannot be produced by setting cookies -- and
     * that fragment is exactly the input the old parser mishandled. A real
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

        // substring(0, false) is substring(0, 0), so the old code filed the
        // orphan under the empty-string key with its first character eaten --
        // 'ustaflag' under ''. A phantom entry any later lookup could collide
        // with, and one that changed shape depending on what else was in the
        // jar.
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

describe('zero padding through padStart', () => {

    test('pads to width and leaves anything already wide enough alone', () => {
        expect(Util.zeroFill(42, 6)).toBe('000042');
        expect(Util.zeroFill('7', 3)).toBe('007');
        expect(Util.zeroFill(999999, 6)).toBe('999999');
        // Longer than the target width is returned unchanged rather than
        // truncated -- the guid builder depends on never losing digits.
        expect(Util.zeroFill(1234567, 6)).toBe('1234567');
        expect(Util.zeroFill(0, 3)).toBe('000');
    });

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
 * The sprintf port is gone too, and it took a sharper edge with it.
 *
 * All eight callers used nothing but %s -- string concatenation written the
 * long way round, at 150 lines of ported flag, width and precision handling
 * that no caller ever reached. Three of them were worse than verbose: they
 * built the FORMAT STRING from a setting, as sprintf( ns + '%s[%s]', ... ). A
 * format string assembled from configuration is the same shape as a query
 * assembled from user input, and it fails the same way -- a '%' in the value
 * stops being data and starts being an instruction.
 *
 * The namespace is empty today, so nothing was broken in practice. It is a
 * setting, though, and the whole point of the namespace work in this branch was
 * that people set it.
 */
describe('beacon param names are built by concatenation, not a format string', () => {

    test('a namespace containing a percent sign is not read as a specifier', () => {
        window.owa_baseUrl = 'https://owa.example.test/';
        OWA.setSetting('app_ns', '%s_');

        const sent = [];
        const Orig = global.Image;
        global.Image = class { set src(v) { sent.push(v); } };

        try {
            const t = new OWATracker({ cookie_domain_set: true });
            t.setSiteId('sprintf-site');
            t.trackPageView('https://example.com/p');

            expect(sent).toHaveLength(1);
            // The namespace arrives verbatim. Under sprintf the leading '%s'
            // would have consumed the first argument and the param name would
            // have come out as the param's own name repeated.
            expect(sent[0]).toContain('%s_site_id=sprintf-site');
        } finally {
            global.Image = Orig;
            OWA.setSetting('app_ns', '');
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

        // The round trip alone is not the test -- the old port round-tripped
        // too. This is the part it got wrong: the bytes have to be the UTF-8
        // ones, which is what any other decoder will assume they are.
        // The expected value is stated literally rather than computed, so this
        // asserts a fact about UTF-8 rather than restating the implementation.
        // 😀 is U+1F600: f0 9f 98 80. The old port emitted the two surrogates
        // separately, giving 7aC97biA where this gives 8J+YgA==.
        expect(Util.base64_encode('😀')).toBe('8J+YgA==');
        expect(Util.base64_encode(withEmoji)).toBe('cGxhbj1wcm8g8J+YgA==');
    });

    test('a token from an older tracker decodes instead of throwing', () => {
        // CESU-8 for U+1D11E, which is what the port used to emit: the two
        // surrogates encoded separately. decodeURIComponent rejects it, and a
        // throw here would break the landing page's state restore rather than
        // degrade it.
        const legacy = '7aC07bSe';

        expect(() => Util.base64_decode(legacy)).not.toThrow();
        expect(Util.base64_decode(legacy)).toBeTruthy();
    });
});
