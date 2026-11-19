<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * `cmd=instance-info` describes the whole instance in one report.
 *
 * WHAT IT IS FOR. Everything it reports can be found out otherwise, but that
 * takes several commands, some SQL and knowing which settings to read, and the
 * things that fail QUIETLY are
 * exactly the things nobody goes looking for: a stale user-agent database
 * misattributes rather than erroring, an un-run scheduler does nothing rather
 * than complaining, retention that was never configured accumulates forever.
 *
 * WHAT THESE TESTS PIN. Two things, and the second is the one that would rot:
 *
 *   1. The report actually reports -- every section present, the counts
 *      answered, and no fatal on the way through.
 *   2. It never becomes a way to BREAK an instance. It is run on installations
 *      that are already unwell, so every reader has to tolerate the thing it
 *      reads on being absent. A missing table, an unreadable setting or a
 *      module that will not load must degrade to a line saying so, never to an
 *      exception -- a diagnostic that dies on a sick instance is worse than
 *      none, because it dies exactly when it is needed.
 */
final class InstanceInfoCliTest extends TestCase
{
    private function root(): string
    {
        return dirname(__DIR__);
    }

    /**
     * Run the command as a person would, and hand back stdout+stderr.
     */
    private function report(string $extra = ''): string
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' '
             . escapeshellarg($this->root() . '/cli.php')
             . ' cmd=instance-info' . ($extra ? ' ' . $extra : '') . ' 2>&1';

        return (string) shell_exec($cmd);
    }

    /**
     * The environment half is shared with the installer, so it must answer
     * without a database, a config file or anything else being present.
     */
    public function testEnvironmentChecksAnswerWithoutADatabase(): void
    {
        $checks = \OWA\Module\Base\Classes\EnvironmentCheck::all(
            \OWA\Core\Env::root() . '/owa-config.php');

        $this->assertNotEmpty($checks);

        $names = array_column($checks, 'name');

        foreach (['PHP version', '64-bit PHP', 'Database driver', 'PHP extension: zlib', 'Dependencies', 'Built assets'] as $expected) {
            $this->assertContains($expected, $names,
                $expected . ' must be one of the environment checks');
        }

        foreach ($checks as $check) {
            $this->assertArrayHasKey('name', $check);
            $this->assertArrayHasKey('value', $check);
            $this->assertArrayHasKey('passed', $check);
            $this->assertArrayHasKey('msg', $check);
            $this->assertIsBool($check['passed']);

            // A failing check that cannot say what to do about it is a check
            // that gets reported and ignored.
            if (!$check['passed']) {
                $this->assertNotSame('', $check['msg'],
                    $check['name'] . ' fails without saying how to fix it');
            }
        }
    }

    public function testTheCommandIsRegistered(): void
    {
        $source = (string) file_get_contents(\OWA\Core\Env::root() . '/modules/Base/Module.php');

        $this->assertStringContainsString("registerCliCommand('instance-info'", $source);
    }

    /**
     * @group needs-db
     */
    public function testTheReportNamesEverySection(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('needs a database to boot the CLI');
        }

        $out = $this->report();

        $this->assertStringNotContainsString('Fatal error', $out,
            'the report must not fatal: ' . substr($out, 0, 500));

        foreach (['ENVIRONMENT', 'MODULES', 'SCHEMA', 'SCHEDULER',
                  'FACT TABLES', 'FRESHNESS', 'TRACKER INGEST', 'SETTINGS',
                  'CONTENTS'] as $section) {

            $this->assertStringContainsString($section, $out,
                'the report is missing its ' . $section . ' section');
        }
    }

    /**
     * Modules and schema are two different questions. They were one section
     * once, which sent a reader looking for "what is switched on" into a list
     * of version numbers.
     *
     * @group needs-db
     */
    public function testModulesAndSchemaAreReportedSeparately(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('needs a database to boot the CLI');
        }

        $out = $this->report();

        $this->assertStringContainsString('Active', $out,
            'the module list must say which modules are active');

        $this->assertStringContainsString('Present but inactive', $out,
            'an inactive module explains a feature that "does not work"');

        $this->assertMatchesRegularExpression('/SCHEMA.*schema \d+/s', $out,
            'the schema section must report a version per module');
    }

    /**
     * @group needs-db
     */
    public function testItCountsWhatTheInstanceHolds(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('needs a database to boot the CLI');
        }

        $out = $this->report();

        foreach (['Organizations', 'Properties', 'Sites', 'Users'] as $label) {

            $this->assertMatchesRegularExpression(
                '/' . $label . '\s+\d+/', $out,
                $label . ' must be reported with a number');
        }
    }

    /** Partitioning is reported for v2's tables: the raw store and every cube. */
    public function testItReportsV2sPartitionedTables(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('needs a database to boot the CLI');
        }

        $out = $this->report();

        $this->assertMatchesRegularExpression('/owa_event_raw\s+(\d+ partitions?|not partitioned)/', $out);

        foreach (\OWA\Module\Base\Classes\Cube\Cubes::existing() as $table) {
            $this->assertStringContainsString($table, $out);
        }

        $this->assertStringNotContainsString('owa_request ', $out, 'v1\'s tables are not v2\'s to partition');
    }

    /**
     * The settings registry has two failure modes that produce no error at all,
     * and this is the only place either is reported.
     *
     * A stored value for a setting nobody declared is loaded and then ignored,
     * so an administrator who saved it sees the code default. A fieldset naming
     * an unregistered or static setting renders an empty row, or a control whose
     * save writes nothing. Neither raises; neither is visible from the screens.
     *
     * @group needs-db
     */
    public function testItReportsTheSettingsRegistry(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('needs a database to boot the CLI');
        }

        $out = $this->report();

        $this->assertMatchesRegularExpression(
            '/Declared\s+\d+ settings? across \d+ modules?/', $out,
            'the report must say how much is declared');

        $this->assertMatchesRegularExpression('/Fetched at boot\s+\d+/', $out,
            'what boot costs is the reason the registry exists');

        $this->assertStringContainsString('Modules declaring', $out,
            'a module still relying on the compatibility clause must be named');
    }

    /**
     * Every module in this repository declares, so the clause the report warns
     * about is not in use here -- and it must say so rather than warn anyway.
     *
     * @group needs-db
     */
    public function testAFullyDeclaredInstanceIsNotWarnedAbout(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('needs a database to boot the CLI');
        }

        $c = \OWA\Core\CoreAPI::configSingleton();

        if ($c->undeclaredModules()) {
            $this->markTestSkipped('this install has a module that has not declared');
        }

        $this->assertMatchesRegularExpression(
            '/\+\s+Modules declaring\s+all of them/', $this->report(),
            'with nothing relying on the fallback the line must read as a pass');
    }

    /**
     * Every module records a version on its FIRST activation -- with tables or
     * without -- so an active module missing one is an anomaly whatever it owns.
     *
     * install() writes a version whenever none is recorded and every table is
     * new, and for a module with no entities the table loop never runs, so that
     * is trivially true. activate() alone writes nothing. A report that excused
     * schemaless modules would be saying they are expected to have no version,
     * which is not what install() does.
     *
     * maxmind_geoip owns no tables, so the warning is shown for it too.
     */
    public function testAMissingSchemaVersionIsAWarningForEveryModule(): void
    {
        $config  = \OWA\Core\CoreAPI::configSingleton();
        $active  = $config->get('maxmind_geoip', 'is_active');
        $version = $config->get('maxmind_geoip', 'schema_version');

        try {
            $config->set('maxmind_geoip', 'is_active', true);
            $config->set('maxmind_geoip', 'schema_version', '');

            $report = implode("\n", (array) $this->invokePrivate('schema'));

        } finally {
            $config->set('maxmind_geoip', 'is_active', $active);
            $config->set('maxmind_geoip', 'schema_version', $version);
        }

        $this->assertMatchesRegularExpression('/^.*  maxmind_geoip\b.*active, but never installed/m', $report);
    }

    /**
     * Colour is for a person at a terminal. Piped into a file or a monitoring
     * check, escape codes are noise the reader cannot turn off -- and shell_exec
     * here is exactly that case, so the report must come back clean.
     *
     * @group needs-db
     */
    public function testNoAnsiEscapesWhenOutputIsNotATerminal(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('needs a database to boot the CLI');
        }

        $this->assertStringNotContainsString("\033[", $this->report(),
            'colour escapes must not reach a pipe');
    }

    /**
     * It reports; it does not change anything: the whole report runs no
     * statement that writes, on the connection it reads through.
     *
     * Needs a database.
     */
    public function testItOnlyReads(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('needs a database to report on');
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $writes = static function () use ($db): array {
            $counted = [];

            foreach ((array) $db->get_results("SHOW SESSION STATUS LIKE 'Com\\_%'") as $row) {
                $row = array_values((array) $row);

                if (preg_match('/^Com_(insert|update|delete|replace|truncate|load|(create|drop|alter|rename)_(table|db|index|view))/', (string) $row[0])) {
                    $counted[(string) $row[0]] = (int) $row[1];
                }
            }

            return $counted;
        };

        $cli = new class extends \OWA\Module\Base\Controller\InstanceInfoCli {
            /** @var list<string> */
            public $lines = [];

            public function __construct()
            {
                \OWA\Core\Base::__construct();
                $this->params = [];
            }

            protected function write($lines)
            {
                $this->lines = (array) $lines;
            }
        };

        $before = $writes();
        $this->assertNotEmpty($before, 'the server reports no statement counters');

        $cli->action();

        $this->assertNotEmpty($cli->lines);
        $this->assertSame($before, $writes(), 'instance-info must only ever read');
    }

    /** A private method of the command, on an instance whose constructor never ran. */
    private function invokePrivate(string $method)
    {
        $cli = (new ReflectionClass(\OWA\Module\Base\Controller\InstanceInfoCli::class))
            ->newInstanceWithoutConstructor();

        return (new ReflectionMethod($cli, $method))->invoke($cli);
    }

    /**
     * The GeoIP line looks for the configured edition's file. An install on the
     * Country edition has no GeoLite2-City.mmdb and is not "not downloaded".
     */
    public function testTheGeoipLineReadsTheConfiguredEdition(): void
    {
        $file   = \OWA\Module\MaxmindGeoip\Classes\Maxmind::dataDir() . 'GeoLite2-Country.mmdb';
        $dir    = dirname($file);
        $config = \OWA\Core\CoreAPI::configSingleton();

        if (file_exists($file)) {
            $this->markTestSkipped("$file already exists; this test would replace it");
        }

        $madeDir  = !is_dir($dir) && @mkdir($dir, 0775, true);
        $active   = $config->get('maxmind_geoip', 'is_active');
        $edition  = $config->get('maxmind_geoip', 'db_edition');

        if (!is_dir($dir) || @file_put_contents($file, 'owatest') === false) {
            $this->markTestSkipped("$dir is not writable");
        }

        try {
            touch($file, (int) strtotime('2001-02-03 12:00:00'));
            $config->set('maxmind_geoip', 'is_active', true);
            $config->set('maxmind_geoip', 'db_edition', 'GeoLite2-Country');

            $line = (string) $this->invokePrivate('geoipStatus');

            $this->assertStringNotContainsString('not downloaded', $line);
            $this->assertStringContainsString('updated 2001-02-03', $line,
                'the line describes the Country file, the one this install reads');

        } finally {
            $config->set('maxmind_geoip', 'is_active', $active);
            $config->set('maxmind_geoip', 'db_edition', $edition);
            @unlink($file);

            if ($madeDir) {
                @rmdir($dir);
            }
        }
    }

    /**
     * Unreadable partition metadata names the server versions the installer
     * requires (DatabaseRequirement) when the server is older, and no version
     * when it is not.
     */
    public function testUnreadablePartitionsNameTheInstallersRequirement(): void
    {
        try {
            \OWA\Module\Base\Classes\DatabaseRequirement::assume('5.7.44');

            $old = (string) $this->invokePrivate('partitionsUnreadableReason');

            \OWA\Module\Base\Classes\DatabaseRequirement::assume('8.0.36');

            $current = (string) $this->invokePrivate('partitionsUnreadableReason');

        } finally {
            \OWA\Module\Base\Classes\DatabaseRequirement::assume(null);
        }

        $this->assertStringContainsString(\OWA\Module\Base\Classes\DatabaseRequirement::MYSQL_MIN, $old);
        $this->assertStringContainsString('MariaDB 10.11', $old);
        $this->assertStringNotContainsString('MySQL 5.7 or later', $old);

        $this->assertStringNotContainsString('or later', $current,
            'a supported server is not told to upgrade');
        $this->assertStringContainsString('The partition list could not be read.', $current);
    }
}
