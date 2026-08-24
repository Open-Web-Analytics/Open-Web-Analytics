import { Util } from '../../modules/Base/src/common/Util.js';

/**
 * The contract every OWA id must satisfy, and the limit it operates under.
 *
 * Ids are numeric and land in a signed BIGINT column -- that is a hard contract
 * this project migrated *to* (the 32-bit to 63-bit dimension id conversion), so
 * these tests exist to stop a future change from quietly breaking it.
 *
 * The generator is `<10-digit unix seconds><9 random digits>`, which uses 60.6
 * of the 63 available bits. That leaves no room to widen the random component,
 * and re-dividing the budget between the time and random halves is provably
 * pointless: coarsening the time bucket by k multiplies the arrivals sharing a
 * bucket by k (so candidate pairs by k^2), divides the bucket count by k, and
 * grows the random space by k -- k cancels exactly, leaving the collision rate
 * a function of the total bit budget and the arrival rate alone.
 *
 * What that costs, quantified rather than hand-waved: ~10^9 values per one-second
 * bucket gives roughly 0.8 expected collisions per year at 10 new visitors per
 * second (864k/day, a large self-hosted install), where a collision silently and
 * permanently merges two visitors. The only way to improve it is a wider id
 * space, which the BIGINT contract forbids.
 *
 * These tests pin the contract, not the internals -- they must keep passing if
 * the digit split is ever revisited, and fail if the id stops being a
 * BIGINT-safe, uniformly distributed, time-ordered number.
 */
describe('generateRandomGuid contract', () => {

    const BIGINT_MAX = 9223372036854775807n;

    test('is all digits, with no sign, separator or exponent', () => {
        for (let i = 0; i < 200; i++) {
            expect(Util.generateRandomGuid()).toMatch(/^\d+$/);
        }
    });

    test('fits a signed BIGINT, with headroom', () => {
        for (let i = 0; i < 200; i++) {
            expect(BigInt(Util.generateRandomGuid())).toBeLessThan(BIGINT_MAX);
        }
    });

    test('survives the round trip through Number without losing precision', () => {
        // Ids exceed Number.MAX_SAFE_INTEGER, so anything that parses one as a
        // float corrupts it. They must be carried as strings client-side.
        const guid = Util.generateRandomGuid();
        expect(guid.length).toBeGreaterThan(15);
        expect(String(BigInt(guid))).toBe(guid);
    });

    test('leads with the unix timestamp, so ids are broadly time-ordered', () => {
        // Insert locality depends on this: a monotonic prefix keeps new rows at
        // the right edge of the primary key rather than scattering them. It is
        // also what a v2 idempotent event would derive its partition date from.
        const now = Math.floor(Date.now() / 1000);
        const prefix = parseInt(Util.generateRandomGuid().substring(0, 10), 10);

        expect(Math.abs(prefix - now)).toBeLessThan(5);
    });

    test('two ids minted in the same second still differ', () => {
        // Not a uniqueness guarantee -- this generator does not offer one, and
        // the header above quantifies exactly that. Within a one-second bucket
        // the space is 10^9, so 2000 draws collide with probability
        // n^2/2N = 2000^2 / 2e9 ~= 0.2%: about one CI run in 500. Asserting
        // 2000-of-2000 asserted a property the design explicitly lacks, and it
        // duly failed on an unrelated pull request.
        //
        // The tolerance still discriminates. Expected collisions here are
        // 0.002; six or more is not something chance produces. A generator that
        // lost its random half -- returning the timestamp alone, or zero-filling
        // a broken rand() -- yields ~1999 of them, not six.
        const DRAWS = 2000;
        const TOLERATED = 5;

        const ids = new Set();

        for (let i = 0; i < DRAWS; i++) {
            ids.add(Util.generateRandomGuid());
        }

        expect(DRAWS - ids.size).toBeLessThanOrEqual(TOLERATED);
    });

    test('the id is not the timestamp alone', () => {
        // What the test above is really for, stated so it cannot flake: with the
        // clock held still, ids must still differ. Two draws from 10^9 collide
        // with probability 1e-9.
        const realClock = Util.getCurrentUnixTimestamp;

        Util.getCurrentUnixTimestamp = () => 1756000000;

        try {
            const ids = new Set();

            for (let i = 0; i < 50; i++) {
                ids.add(Util.generateRandomGuid());
            }

            expect(ids.size).toBeGreaterThan(45);

            // ...and the frozen clock really was used, or this proves nothing.
            expect(Util.generateRandomGuid().substring(0, 10)).toBe('1756000000');
        } finally {
            Util.getCurrentUnixTimestamp = realClock;
        }
    });

    test('the random component is uniform across its full range', () => {
        // A biased or short random half would shrink the space well below the
        // 10^9 the collision estimate above assumes.
        const buckets = new Array(10).fill(0);
        const runs = 20000;

        for (let i = 0; i < runs; i++) {
            const suffix = Util.generateRandomGuid().substring(10);
            expect(suffix).toHaveLength(9);
            buckets[parseInt(suffix.charAt(0), 10)]++;
        }

        // Each leading digit should land near a tenth of the runs. Generous
        // bounds -- this catches a stuck or truncated generator, not drift.
        buckets.forEach((count) => {
            expect(count).toBeGreaterThan(runs / 20);
            expect(count).toBeLessThan(runs / 5);
        });
    });

    test('accepts no arguments -- there is no salt', () => {
        // Every call site once passed a salt that the function never declared
        // and silently discarded. It cannot be honoured: the budget is
        // saturated, so mixing a salt in would either take bits from the random
        // half or make ids predictable from their inputs.
        expect(Util.generateRandomGuid).toHaveLength(0);
    });
});
