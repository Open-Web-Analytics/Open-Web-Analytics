<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The recorder's limits (Recorder.js LIMITS and MAX_TEXT) are within what the
 * server accepts of a chunk (Domstream\Classes\Chunk): a recorder sending past
 * them has its chunk refused whole.
 */
final class DomstreamLimitsTest extends TestCase
{
    private static function recorder(): string
    {
        return (string) file_get_contents(__DIR__ . '/../modules/Domstream/src/tracker/Recorder.js');
    }

    private static function limit(string $name): int
    {
        if (!preg_match('/\b' . preg_quote($name, '/') . ':\s*(\d+)/', self::recorder(), $m)) {
            self::fail("Recorder.js has no LIMITS.$name");
        }

        return (int) $m[1];
    }

    public function testTheRecordersLimitsAreWithinTheServers(): void
    {
        $chunk = \OWA\Module\Domstream\Classes\Chunk::class;

        $this->assertLessThanOrEqual($chunk::MAX_SAMPLES, self::limit('domstreamMaxSamples'));
        $this->assertLessThan($chunk::MAX_CHARS, self::limit('domstreamMaxChars'), 'with room for the sample that crosses it');
        $this->assertLessThanOrEqual($chunk::MAX_DT_MSEC, self::limit('domstreamMaxDurationMsec'));

        $this->assertMatchesRegularExpression('/const MAX_TEXT = ' . $chunk::MAX_TEXT . ';/', self::recorder());
    }

    public function testAChunkFitsABeaconBodyOnceEncoded(): void
    {

        // Form-urlencoded, a chunk's samples are about twice their characters;
        // a keepalive fetch or sendBeacon takes at most 64 KiB.
        $this->assertLessThan(65536, 2 * self::limit('domstreamMaxChars') + 4096);
    }
}
