<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * The OWA_USE_STATIC_CONFIG_ONLY switch, the 1.x name (OWA_SERVER_ROLE
 * collector is the 2.0 way). It applies only to a collector, which opens no
 * database connection at boot. On a role with a database it is ignored: the
 * stored settings are read, schema_version among them.
 *
 * WHY A SUBPROCESS
 * The constants are process-global defines read once at boot, and this runner
 * has already booted OWA as a singleton. A fresh boot under each define can only
 * be observed in a clean PHP process, so each case shells to
 * fixtures/static_config_boot_probe.php and reads its JSON verdict. The probe
 * reports isConnectionEstablished() -- the reliable "did boot touch the DB?"
 * signal, since the mysql driver connects lazily on first query.
 */
final class StaticConfigBootTest extends TestCase
{
    private const PROBE = __DIR__ . '/fixtures/static_config_boot_probe.php';

    protected function setUp(): void
    {
        // The probe boots the real logger; without a reachable DB the default
        // case can't demonstrate a connection, so skip cleanly (matches the
        // other DB-backed suites' behavior on config-less CI).
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; skipping static-config boot test.');
        }
    }

    /** Boot the probe in a clean process and return its parsed verdict. */
    private function probe(bool $static, bool $collector = false): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::PROBE);
        if ($static) {
            $cmd .= ' static';
        }
        if ($collector) {
            $cmd .= ' collector';
        }
        // 2>/dev/null: OWA's boot emits deprecation notices under some PHP
        // builds that would corrupt the single-line JSON we parse. Errors that
        // actually break the boot still surface as invalid/empty JSON below.
        $out = shell_exec($cmd . ' 2>/dev/null');
        $decoded = json_decode(trim((string) $out), true);
        $this->assertIsArray($decoded,
            "boot probe did not emit parseable JSON (static=" . var_export($static, true) . "); got: " . var_export($out, true));
        return $decoded;
    }

    public function testOnAFullServerTheSwitchIsIgnoredAndStoredSettingsAreRead(): void
    {
        $default = $this->probe(false);
        $verdict = $this->probe(true);

        $this->assertTrue($verdict['static'], 'probe should have run in static-config mode');
        $this->assertTrue($verdict['connected'], 'a full server reads its stored settings');
        $this->assertGreaterThan(0, (int) ($verdict['base']['schema_version'] ?? 0));
        $this->assertSame((int) ($default['base']['schema_version'] ?? 0), (int) ($verdict['base']['schema_version'] ?? 0),
            'the stored schema_version, not the default');
    }

    public function testOnACollectorTheSwitchStillApplies(): void
    {
        $verdict = $this->probe(true, true);

        $this->assertTrue($verdict['static'], 'probe should have run in static-config mode');
        $this->assertFalse($verdict['connected'], 'a collector opens no database connection at boot');
    }

    public function testDefaultBootStillLoadsConfigFromDatabase(): void
    {
        $verdict = $this->probe(false);

        $this->assertFalse($verdict['static'], 'probe should have run in default mode');
        $this->assertTrue($verdict['connected'],
            'A normal boot must still read the stored settings '
            . '(the switch is opt-in; the default is unchanged).');
    }
}
