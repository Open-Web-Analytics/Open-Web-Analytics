<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * `cmd=instance-info` describes the whole instance in one report.
 *
 * WHAT IT IS FOR. Everything it reports could already be found out -- and that
 * was the problem. It took several commands, some SQL and knowing which
 * settings to read, so nobody did it, and the things that fail QUIETLY are
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
            OWA_DIR . 'owa-config.php');

        $this->assertNotEmpty($checks);

        $names = array_column($checks, 'name');

        foreach (['PHP version', 'Database driver', 'Dependencies', 'Built assets'] as $expected) {
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

    /**
     * The installer must not keep its own copy of those checks. If it grows one
     * back, the two drift and nobody notices -- nobody runs the installer on a
     * working instance, or the diagnostic on a fresh one.
     */
    public function testTheInstallerReadsTheSharedEnvironmentChecks(): void
    {
        $source = (string) file_get_contents(
            OWA_DIR . 'modules/Base/Controller/InstallCheckEnv.php');

        $this->assertStringContainsString('EnvironmentCheck::all(', $source,
            'the installer must ask EnvironmentCheck rather than rebuild the list');

        $this->assertStringNotContainsString('extension_loaded(', $source,
            'the installer is testing extensions itself again');

        $this->assertStringNotContainsString("is_dir( OWA_VENDOR_DIR )", $source,
            'the installer is testing for vendor/ itself again');
    }

    public function testTheCommandIsRegistered(): void
    {
        $source = (string) file_get_contents(OWA_DIR . 'modules/Base/Module.php');

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
                  'FACT TABLES', 'FRESHNESS', 'EVENT QUEUE', 'SETTINGS',
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
            '/Declared\s+\d+ setting\(s\) across \d+ module\(s\)/', $out,
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
     * Asserted against the source because the state does not exist on a healthy
     * install -- every active module here and on both production installs has a
     * recorded version -- so there is nothing to render and nothing to observe.
     */
    public function testAMissingSchemaVersionIsAWarningForEveryModule(): void
    {
        $src = (string) file_get_contents(
            OWA_DIR . 'modules/Base/Controller/InstanceInfoCli.php');

        $this->assertStringContainsString('active, but never installed', $src);

        $this->assertStringNotContainsString('hasSchema', $src,
            'the warning must not be gated on whether the module owns tables: '
          . 'install() records a version for a schemaless module too');
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
     * It reports; it does not change anything. Asserted against the source
     * because the destructive case cannot be exercised safely.
     */
    public function testItOnlyReads(): void
    {
        $source = (string) file_get_contents(
            OWA_DIR . 'modules/Base/Controller/InstanceInfoCli.php');

        /*
         * Match SQL STATEMENTS, not words. A plain search for "UPDATE " fails
         * on the string 'UPDATE REQUIRED' that the schema section prints, which
         * would be a test that has to be weakened the first time it is right
         * about nothing.
         */
        $writes = '/\b(INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM'
                . '|DROP\s+(TABLE|DATABASE)|ALTER\s+TABLE|TRUNCATE\s+(TABLE\s+)?\w)/i';

        $this->assertDoesNotMatchRegularExpression($writes, $source,
            'instance-info must only ever read');
    }
}
