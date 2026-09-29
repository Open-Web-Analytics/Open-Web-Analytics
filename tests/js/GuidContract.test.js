import { Util } from '../../modules/Base/src/common/Util.js';

/**
 * The contract every tracker-minted id must satisfy: visitor, session and
 * recording ids.
 *
 * Numeric and within a signed BIGINT -- a hard contract this project migrated
 * TO (the 32-bit to 63-bit dimension id conversion) -- and as random as that
 * allows: 62 bits, with bit 62 set so every id is exactly 19 digits.
 *
 * It used to lead with 10 digits of unix seconds, leaving about 30 random bits
 * per second: roughly 0.8 collisions a year at 10 new visitors a second, each
 * silently merging two visitors or two sessions. The seconds spent ~33 bits
 * encoding three centuries an installation never sees. Among n ids of 62
 * random bits the expected collisions are n^2 / 2^63.
 */
describe('generateRandomGuid contract', () => {

    const LOW  = 4611686018427387904n; // 2^62
    const HIGH = 9223372036854775807n; // 2^63 - 1, BIGINT's max

    test('is all digits, with no sign, separator or exponent', () => {
        for (let i = 0; i < 200; i++) {
            expect(Util.generateRandomGuid()).toMatch(/^\d+$/);
        }
    });

    test('is always 19 digits, within a signed BIGINT', () => {
        for (let i = 0; i < 2000; i++) {
            const id = Util.generateRandomGuid();
            const n = BigInt(id);

            expect(id).toHaveLength(19);
            expect(n >= LOW).toBe(true);
            expect(n <= HIGH).toBe(true);
        }
    });

    test('survives the round trip through a string, and not through Number', () => {
        // Every id exceeds Number.MAX_SAFE_INTEGER, so anything that parses one
        // as a float corrupts it. They must be carried as strings client-side.
        const id = Util.generateRandomGuid();

        expect(String(BigInt(id))).toBe(id);
        expect(BigInt(id) > BigInt(Number.MAX_SAFE_INTEGER)).toBe(true);
    });

    test('does not repeat', () => {
        // 10,000 draws from 2^62 collide with probability ~1e-11.
        const ids = new Set();

        for (let i = 0; i < 10000; i++) {
            ids.add(Util.generateRandomGuid());
        }

        expect(ids.size).toBe(10000);
    });

    test('is uniform across its whole range, not only its low digits', () => {
        // A generator that lost a word, or filled one from a stuck source, would
        // pile ids into part of the range.
        const buckets = new Array(10).fill(0);
        const runs = 20000;

        for (let i = 0; i < runs; i++) {
            const offset = BigInt(Util.generateRandomGuid()) - LOW;
            buckets[Number((offset * 10n) / LOW)]++;
        }

        buckets.forEach((count) => {
            expect(count).toBeGreaterThan(runs / 20);
            expect(count).toBeLessThan(runs / 5);
        });
    });

    test('draws from crypto.getRandomValues, not Math.random', () => {
        const spy = jest.spyOn(globalThis.crypto, 'getRandomValues');
        const math = jest.spyOn(Math, 'random');

        try {
            Util.generateRandomGuid();

            expect(spy).toHaveBeenCalled();
            expect(math).not.toHaveBeenCalled();
        } finally {
            spy.mockRestore();
            math.mockRestore();
        }
    });

    test('converts to decimal exactly', () => {
        expect(Util.wordsToDecimal([0x4000, 0, 0, 0])).toBe('4611686018427387904');
        expect(Util.wordsToDecimal([0x7fff, 0xffff, 0xffff, 0xffff])).toBe('9223372036854775807');
        expect(Util.wordsToDecimal([0x4000, 0x0000, 0x0000, 0x0001])).toBe('4611686018427387905');
        expect(Util.wordsToDecimal([0, 0, 0, 0])).toBe('0');
    });

    test('accepts no arguments -- there is no salt', () => {
        // Every call site once passed a salt that the function never declared
        // and silently discarded. Mixing one in would make ids predictable from
        // their inputs.
        expect(Util.generateRandomGuid).toHaveLength(0);
    });
});
