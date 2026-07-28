<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * Locks the OWA_USE_STATIC_CONFIG_ONLY switch: when defined true in the config
 * environment, OWA must NOT open a database connection during boot.
 *
 * WHY IT MATTERS
 * Every OWA process normally issues two DB queries at boot -- a connection
 * handshake (SET SESSION sql_mode='') and the user-settings read
 * (SELECT * FROM owa_configuration ...) at owa_caller.php:~97. They are one
 * cause: the config read opens the (lazily-connected) DB handle, which triggers
 * the handshake. A dedicated node that only queues incoming tracking events to a
 * file needs neither. OWA_USE_STATIC_CONFIG_ONLY skips the config read, so such
 * a node accepts + queues beacons with zero DB access. This guards that:
 *   - the switch is wired into owa_settings::applyConfigConstants (so a
 *     config-file define reaches the gate in time), and
 *   - a normal boot still DOES connect (the switch is opt-in, not the default).
 *
 * WHY A SUBPROCESS
 * The switch is a process-global define consumed once at boot, and this runner
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
    private function probe(bool $static): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::PROBE);
        if ($static) {
            $cmd .= ' static';
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

    public function testStaticConfigBootDoesNotTouchTheDatabase(): void
    {
        $verdict = $this->probe(true);

        $this->assertTrue($verdict['static'], 'probe should have run in static-config mode');
        $this->assertFalse($verdict['connected'],
            'With OWA_USE_STATIC_CONFIG_ONLY defined true, boot must not open a DB connection '
            . '(no owa_configuration read => no connection handshake).');
    }

    public function testDefaultBootStillLoadsConfigFromDatabase(): void
    {
        $verdict = $this->probe(false);

        $this->assertFalse($verdict['static'], 'probe should have run in default mode');
        $this->assertTrue($verdict['connected'],
            'A normal boot must still read settings from the owa_configuration table '
            . '(the switch is opt-in; the default is unchanged).');
    }
}
