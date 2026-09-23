<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The declaration agrees with everything else that describes a setting.
 *
 * None of this is visible to static analysis -- it is all data, in a returned
 * array -- so it is asserted here instead. Every check is derived from the
 * other source rather than from a list written in this file, so the contract
 * keeps holding as settings are added.
 *
 * What each one prevents is a silent failure, not a crash:
 *
 *   a key Base owns but does not declare leaves the wholesale clause and is
 *   then never read;
 *
 *   a config-file-only key that becomes storable is an arbitrary file include
 *   waiting for someone to write the row;
 *
 *   a fieldset listing an unstorable setting renders a form whose save does
 *   nothing.
 */
final class SettingsDeclarationContractTest extends TestCase
{
    private function settings()
    {
        return \OWA\Core\CoreAPI::configSingleton();
    }

    /**
     * The LITERAL defaults, as getDefaultSettingsArray() returns them.
     *
     * Not $c->default_config, which is the live array: applyConfigConstants()
     * and setupPaths() have already written into it, so on an installation
     * whose config file sets async_log_dir it holds that path rather than the
     * code's ''. Comparing a static declaration against a mutated array makes
     * the test fail wherever the installation differs from the developer's --
     * which is exactly what the isolation sweep found, running from a scratch
     * install with its own log directory configured.
     *
     * @return array<string,mixed>
     */
    private function literalDefaults(): array
    {
        $m = new ReflectionMethod( $this->settings(), 'getDefaultSettingsArray' );
        $m->setAccessible( true );

        return (array) ( $m->invoke( $this->settings() )['base'] ?? array() );
    }

    /** @return array<string,mixed> */
    private function baseDeclaration(): array
    {
        $file = OWA_DIR . 'modules/Base/settings.php';

        $this->assertFileExists( $file, 'Base must ship a declaration' );

        $decl = include $file;

        $this->assertIsArray( $decl );
        $this->assertSame( 'base', $decl['module'] ?? null,
            'the file names its own module; core cannot derive it from the directory' );

        return (array) $decl['settings'];
    }

    /**
     * Every setting Base has a code default for is declared.
     *
     * The completeness guarantee. A key present in getDefaultSettingsArray()
     * but missing from the declaration is one Base no longer fetches at boot,
     * because declaring anything takes Base out of the wholesale clause.
     */
    public function testEveryBaseDefaultIsDeclared(): void
    {
        $declared = $this->baseDeclaration();
        $defaults = $this->literalDefaults();

        $missing = array_diff( array_keys( $defaults ), array_keys( $declared ) );

        // Core adds these to every module; the file must NOT declare them.
        $missing = array_diff( $missing, array_keys( \OWA\Core\Module::mechanicalSettings() ) );

        $this->assertSame( array(), array_values( $missing ), sprintf(
            'undeclared Base settings: %s. Declaring anything takes Base out of the '
          . 'wholesale boot clause, so a key left out is one nothing reads.',
            implode( ', ', $missing ) ) );
    }

    /** And the declaration does not invent settings the code has no default for. */
    public function testDeclaredDefaultsMatchTheCodeDefaults(): void
    {
        $declared = $this->baseDeclaration();
        $defaults = $this->literalDefaults();

        foreach ( $declared as $key => $args ) {

            if ( ! array_key_exists( 'default', (array) $args ) ) {

                continue;
            }

            $this->assertArrayHasKey( $key, $defaults,
                sprintf( 'base.%s declares a default that getDefaultSettingsArray() '
                       . 'does not have; the two would drift', $key ) );

            $this->assertSame( $defaults[ $key ], $args['default'],
                sprintf( 'base.%s declares a different default from the code', $key ) );
        }
    }

    /**
     * Every config-file-only setting is declared static.
     *
     * The list is a security control: a stored error_log_file or
     * report_wrapper is an RCE primitive. Two of the three runtime strips were
     * removed once the declaration made them redundant, so this is what keeps
     * that true.
     */
    public function testEveryConfigFileOnlySettingIsDeclaredStatic(): void
    {
        $declared = $this->baseDeclaration();

        foreach ( array_keys(
            (array) ( \OWA\Module\Base\Classes\Settings::staticSettings()['base'] ?? array() ) )
            as $key ) {

            $args = (array) ( $declared[ $key ] ?? array() );

            $this->assertArrayNotHasKey( 'storable', $args, sprintf(
                'base.%s is config-file-only and must never be storable', $key ) );

            $this->assertFalse( $this->settings()->mayPersistInstallWide( 'base', $key ),
                sprintf( 'base.%s must be refused by persistSetting()', $key ) );
        }
    }

    /**
     * Database-state settings are storable, and carry no default.
     *
     * schema_version and install_complete record what has happened to this
     * installation. A default would put them within reach of
     * pruneRedundantPersistedSettings(), and dropping one makes a module look
     * uninstalled and re-run its updates.
     */
    public function testDatabaseStateSettingsAreStorableWithNoDefault(): void
    {
        $declared = $this->baseDeclaration();
        $mechanical = \OWA\Core\Module::mechanicalSettings();

        foreach ( array_keys(
            (array) ( \OWA\Module\Base\Classes\Settings::databaseStateSettings()['base'] ?? array() ) )
            as $key ) {

            if ( isset( $mechanical[ $key ] ) ) {

                $this->assertArrayNotHasKey( 'default', $mechanical[ $key ],
                    sprintf( 'core must not give %s a default', $key ) );

                continue;
            }

            $args = (array) ( $declared[ $key ] ?? array() );

            /*
             * The property that matters is that the PRUNE CANNOT DROP IT, and
             * there are two ways to be safe from it. Either the setting has no
             * default, so no stored value can ever "merely restate" one -- that
             * is schema_version and install_complete. Or it is not storable, so
             * it never reaches db_settings, which is the only thing the prune
             * walks. configuration_id is the second kind: it named the blob row
             * Update043 retired, and is vestigial rather than state now.
             *
             * Asserting "no default" alone would be testing a proxy, and would
             * fail on a setting that is perfectly safe.
             */
            $safe = ! array_key_exists( 'default', $args )
                 || ! $this->settings()->mayPersistInstallWide( 'base', $key );

            $this->assertTrue( $safe, sprintf(
                'base.%s records installation state and the prune could drop it: it '
              . 'has a default AND can be persisted. Dropping it makes the module '
              . 'look uninstalled and re-run its updates.', $key ) );
        }
    }

    /**
     * Every setting the code persists is declared storable.
     *
     * Derived by scanning for persistSetting() and setSetting(..., true) call
     * sites rather than from a list here, because the list is what went wrong:
     * base.queue_incoming_tracking_events has no code default and was not
     * stored on the machine this declaration was generated from, so neither
     * source saw it. The e2e queue suite found it -- the helper persisted the
     * value, nothing read it back, and every beacon bypassed the queue.
     *
     * An undeclared key of a declared module is written and then never
     * fetched. That is deliberate, and it is why this has to be checked.
     */
    public function testEverySettingTheCodePersistsIsDeclaredStorable(): void
    {
        $roots = array( OWA_DIR . 'Core', OWA_DIR . 'modules', OWA_DIR . 'tests' );

        $found = array();

        foreach ( $roots as $root ) {

            $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );

            foreach ( $it as $file ) {

                if ( $file->getExtension() !== 'php' ) {
                    continue;
                }

                $src = (string) file_get_contents( $file->getPathname() );

                if ( preg_match_all(
                    "/persistSetting\(\s*'([a-z_]+)'\s*,\s*'([a-zA-Z_]+)'/",
                    $src, $m, PREG_SET_ORDER ) ) {

                    foreach ( $m as $hit ) {
                        $found[ $hit[1] . '|' . $hit[2] ] = true;
                    }
                }
            }
        }

        $this->assertNotEmpty( $found, 'the scan found no persistSetting() calls at all' );

        $c = $this->settings();

        foreach ( array_keys( $found ) as $id ) {

            list( $module, $key ) = explode( '|', $id, 2 );

            // Probe keys invented by tests are not product settings.
            if ( strpos( $key, 'zz_' ) === 0 || strpos( $module, 'zz_' ) === 0 ) {
                continue;
            }

            if ( ! $c->isRegistered( $module, $key ) ) {

                // Only modules that have declared are held to this; an
                // un-adopted module is still resolved wholesale.
                $declared = (array) ( \OWA\Core\Module::settingsRegistry()['declared'] ?? array() );

                $this->assertArrayNotHasKey( $module, $declared, sprintf(
                    '%s.%s is persisted by code but %s does not declare it, so the '
                  . 'stored value is written and never read', $module, $key, $module ) );

                continue;
            }

            $this->assertTrue( $c->mayPersistInstallWide( $module, $key ), sprintf(
                '%s.%s is persisted by code but is not storable', $module, $key ) );
        }
    }

    /** No settings screen renders a field that cannot work. */
    public function testNoFieldSetRendersAnImpossibleField(): void
    {
        $problems = $this->settings()->fieldSetProblems();

        $this->assertSame( array(), $problems, "\n  " . implode( "\n  ", $problems ) );
    }

    /**
     * And nothing stored is orphaned by a declaration.
     *
     * Needs a database, because it compares the declaration against what is
     * actually in the table. On CI that is the scratch install, which is
     * exactly where an installer writing a key nobody declared would show up.
     */
    public function testNothingStoredIsOrphanedByADeclaration(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'compares the declaration against stored rows' );
        }

        $problems = $this->settings()->declarationProblems();

        $this->assertSame( array(), $problems, "\n  " . implode( "\n  ", $problems ) );
    }
}
