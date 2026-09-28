<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * OWA resolves its own classes without the compatibility map.
 *
 * owa_compat_aliases.php is for third parties: the v2 set of legacy names a
 * module builds on. OWA's own factories used to reach 25 names through it --
 * all eleven validators, the configurable metric, four event handlers, three
 * views, the template, the request container, the queue and cache
 * implementations, and 38 registry callbacks written as
 * owa_trackingEventHelpers::. Trimming the map would have broken OWA itself.
 *
 * The probe boots OWA in a clean process with OWA_DISABLE_COMPAT_BRIDGE set --
 * no aliasing autoloader, and no map lookups -- and drives every factory family
 * through what is actually registered. Anything that still needs the map fails.
 */
final class CompatMapIsNotLoadBearingTest extends TestCase
{
    const PROBE = __DIR__ . '/fixtures/compat_free_boot_probe.php';

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; the probe boots a full instance.');
        }
    }

    public function testEveryFactoryFamilyResolvesWithTheMapOff(): void
    {
        // 2>/dev/null: boot notices would corrupt the one line of JSON. A boot
        // that breaks still shows, as unparseable output.
        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::PROBE) . ' 2>/dev/null');
        $verdict = json_decode(trim((string) $out), true);

        $this->assertIsArray($verdict, 'the probe emitted no JSON; got: ' . var_export($out, true));

        $this->assertSame([], $verdict['errors'],
            "these resolved only through the compat map:\n" . implode("\n", $verdict['errors']));

        // Each family was actually driven, or the empty error list means nothing.
        foreach (['metric' => 30, 'entity' => 30, 'validator' => 11, 'view' => 4,
                  'handler' => 4, 'implementation' => 3, 'callback' => 30] as $family => $floor) {
            $this->assertGreaterThanOrEqual($floor, $verdict['checked'][$family] ?? 0,
                "the probe drove too few of: $family");
        }
    }
}
