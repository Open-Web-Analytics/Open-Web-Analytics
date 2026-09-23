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
        $defaults = $this->settings()->default_config['base'];

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
        $defaults = $this->settings()->default_config['base'];

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
