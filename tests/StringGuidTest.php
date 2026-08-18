<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * Content-derived ids must be positive, must fit a signed BIGINT, and must be
 * the same everywhere.
 *
 * WHY THIS EXISTS
 * OWA derives dimension ids from the content itself rather than from a sequence,
 * so that any node can compute the id without asking the database. That only
 * works if the derivation is total and stable: every node, every PHP version,
 * every platform must agree, and the result must be storable.
 *
 * The wide-hash scheme did not meet that bar. `hexdec( substr( sha1( $s ), 0, 16 ) )`
 * asks for a full 64 bits when PHP_INT_MAX is 2^63-1, so PHP returned a float
 * for about half of all inputs and the cast to int wrapped those negative.
 * Floats carry 53 bits of mantissa, so the low bits were gone before the cast,
 * and casting an out-of-range float is undefined behaviour in PHP -- it wraps
 * consistently on x86-64 today, and nothing promises it will elsewhere.
 *
 * These cases pin the replacement and, in one case, the defect itself, so that
 * anyone tempted by the shorter expression can see what it does.
 */
final class StringGuidTest extends TestCase
{
    private const SAMPLES = 20000;

    /** @return string[] */
    private function corpus(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = 'https://example.com/' . bin2hex(random_bytes(8)) . '/' . $i;
        }
        return $out;
    }

    public function testEveryIdIsAPositiveIntegerInsideSignedBigint(): void
    {
        $negative = 0;
        $floats   = 0;

        foreach ($this->corpus(self::SAMPLES) as $s) {
            $id = \OWA\Core\Lib::wideStringGuid($s);

            if (is_float($id)) {
                $floats++;
            }
            if ($id < 0) {
                $negative++;
            }
            $this->assertLessThanOrEqual(PHP_INT_MAX, $id);
        }

        $this->assertSame(0, $floats, 'an id became a float, so its low bits were lost to mantissa precision');
        $this->assertSame(0, $negative, 'an id came out negative, which means the value overflowed and wrapped');
    }

    /**
     * The defect this replaced, kept executable so the reason is not just a
     * comment. If this ever stops failing, PHP's integer range changed and the
     * shorter expression could be reconsidered.
     */
    public function testTheNaiveSixteenCharacterFormOverflows(): void
    {
        $negative = 0;
        $sample   = $this->corpus(2000);

        foreach ($sample as $s) {
            if ((int) hexdec(substr(sha1(strtolower($s)), 0, 16)) < 0) {
                $negative++;
            }
        }

        $this->assertGreaterThan(
            count($sample) * 0.3,
            $negative,
            'hexdec() over 16 hex characters is expected to overflow for roughly half of all inputs'
        );
    }

    public function testTheSameContentAlwaysDerivesTheSameId(): void
    {
        foreach (['https://example.com/', 'Mozilla/5.0', 'a', str_repeat('x', 4096)] as $s) {
            $this->assertSame(
                \OWA\Core\Lib::wideStringGuid($s),
                \OWA\Core\Lib::wideStringGuid($s),
                'derivation must be deterministic: nodes derive ids independently and must agree'
            );
        }
    }

    public function testDerivationIsCaseInsensitive(): void
    {
        $this->assertSame(
            \OWA\Core\Lib::wideStringGuid('https://Example.COM/Page'),
            \OWA\Core\Lib::wideStringGuid('https://example.com/page'),
            'the crc32 scheme lowercased before hashing; the wide scheme must not change that'
        );
    }

    public function testDistinctContentDerivesDistinctIds(): void
    {
        $seen = [];

        foreach ($this->corpus(self::SAMPLES) as $s) {
            $seen[\OWA\Core\Lib::wideStringGuid($s)] = true;
        }

        $this->assertCount(self::SAMPLES, $seen,
            'a collision in ' . self::SAMPLES . ' values would mean far less key space than 63 bits');
    }

    public function testTheWideSchemeUsesTheRangeCrc32CannotReach(): void
    {
        $above32 = 0;

        foreach ($this->corpus(2000) as $s) {
            if (\OWA\Core\Lib::wideStringGuid($s) > 0xFFFFFFFF) {
                $above32++;
            }
        }

        $this->assertGreaterThan(1900, $above32,
            'almost every id should land beyond the 32-bit range, or the extra bits are not being used');
    }

    /** The switch still decides which scheme is in force. */
    public function testSetStringGuidFollowsTheSetting(): void
    {
        $was = \OWA\Core\CoreAPI::getSetting('base', 'use_64bit_hash');

        try {
            \OWA\Core\CoreAPI::setSetting('base', 'use_64bit_hash', false);
            $this->assertSame((string) crc32('https://example.com/'), (string) \OWA\Core\Lib::setStringGuid('https://example.com/'));

            \OWA\Core\CoreAPI::setSetting('base', 'use_64bit_hash', true);
            $this->assertSame(
                (string) \OWA\Core\Lib::wideStringGuid('https://example.com/'),
                (string) \OWA\Core\Lib::setStringGuid('https://example.com/')
            );
        } finally {
            \OWA\Core\CoreAPI::setSetting('base', 'use_64bit_hash', $was);
        }
    }

    public function testAnEmptyStringHasNoId(): void
    {
        $this->assertNull(\OWA\Core\Lib::setStringGuid(''));
        $this->assertNull(\OWA\Core\Lib::setStringGuid(null));
    }
}
