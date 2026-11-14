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

        return (array) ( $m->invoke( $this->settings() )['base'] ?? array() );
    }

    /** @return array<string,mixed> */
    private function baseDeclaration(): array
    {
        $file = \OWA\Core\Env::root() . '/modules/Base/settings.php';

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
        $roots = array( \OWA\Core\Env::root() . '/Core', \OWA\Core\Env::root() . '/modules', \OWA\Core\Env::root() . '/tests' );

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

    /**
     * Every setting the code READS is declared, on a module that has declared.
     *
     * The other half of the persist sweep, and the half that actually bites.
     * Declaring takes a module out of the boot query's wholesale clause, so
     * from that moment any key it did not declare is one whose stored value is
     * never fetched -- written, kept, and ignored.
     *
     * That is not hypothetical. It shipped twice in this branch:
     * base.queue_incoming_tracking_events, which the e2e queue suite caught
     * after every beacon bypassed the file queue; and three MaxmindGeoip
     * settings -- lookup_method and the two web-service credentials -- which
     * nothing caught, because that module's declaration was hand-written from
     * the two fields its options screen happens to show.
     *
     * Scanned rather than listed, for the same reason the persist sweep is.
     */
    public function testEverySettingTheCodeReadsIsDeclared(): void
    {
        $roots = array( \OWA\Core\Env::root() . '/Core', \OWA\Core\Env::root() . '/modules' );

        $found = array();

        foreach ( $roots as $root ) {

            $it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root ) );

            foreach ( $it as $file ) {

                if ( $file->getExtension() !== 'php' ) {
                    continue;
                }

                if ( preg_match_all(
                    "/getSetting\(\s*'([a-zA-Z_]+)'\s*,\s*'([a-zA-Z_.]+)'/",
                    (string) file_get_contents( $file->getPathname() ), $m, PREG_SET_ORDER ) ) {

                    foreach ( $m as $hit ) {
                        $found[ $hit[1] . '|' . $hit[2] ] = true;
                    }
                }
            }
        }

        $this->assertNotEmpty( $found, 'the scan found no getSetting() calls at all' );

        $c        = $this->settings();
        $declared = (array) ( \OWA\Core\Module::settingsRegistry()['declared'] ?? array() );

        $undeclared = array();

        foreach ( array_keys( $found ) as $id ) {

            list( $module, $key ) = explode( '|', $id, 2 );

            // A module that has not adopted is still resolved wholesale.
            if ( ! isset( $declared[ $module ] ) ) {
                continue;
            }

            if ( ! $c->isRegistered( $module, $key ) ) {
                $undeclared[] = $module . '.' . $key;
            }
        }

        $this->assertSame( array(), $undeclared, sprintf(
            "read but not declared: %s\n"
          . 'Each is a setting whose stored value would be written and never read, '
          . 'because declaring anything takes the module out of the wholesale clause.',
            implode( ', ', $undeclared ) ) );
    }

    /** No settings screen renders a field that cannot work. */
    public function testNoFieldSetRendersAnImpossibleField(): void
    {
        $problems = $this->settings()->fieldSetProblems();

        $this->assertSame( array(), $problems, "\n  " . implode( "\n  ", $problems ) );
    }

    /**
     * The fieldsets each screen below the install saves, by level, read from
     * the controllers that save them.
     *
     * @return array<string, array<string, array>> level => screen => fieldsets
     */
    private function screensBelowTheInstall(): array
    {
        $call = function ( string $class, string $method ): array {
            $m = new ReflectionMethod( $class, $method );

            return (array) $m->invoke( null );
        };

        $this->assertMatchesRegularExpression( "#groupFieldSets\(\s*'tracking_tag'\s*\)#",
            (string) file_get_contents( \OWA\Core\Env::root() . '/modules/Base/Controller/SitesEditTagSettings.php' ),
            'the Profile\'s Tracking Tag screen saves the tracking_tag group' );

        return array(
            'profile' => array(
                'Observation settings' => $call( \OWA\Module\Base\Controller\SitesEditSettings::class, 'scopedFieldSets' ),
                'Tracking tag'         => \OWA\Module\Base\Classes\SettingsForm::groupFieldSets( 'tracking_tag' ),
            ),
            'property' => array(
                'Details'        => $call( \OWA\Module\Base\Controller\PropertyEdit::class, 'scopedFieldSets' ),
                'Data retention' => array( $call( \OWA\Module\Base\Controller\PropertyRetentionUpdate::class, 'fieldSet' ) ),
            ),
        );
    }

    /**
     * Every level below the install that a setting declares, a screen offers.
     *
     * A level nothing offers is one nobody can set: no screen, CLI or REST call
     * writes a scoped row but these screens. Settings of modules that are not
     * active are skipped, since their fieldsets register only when they are.
     * `internal` ones are skipped too: nobody configures them, and goals keeps
     * its Profile scope for the 1.x rows Update061 reads.
     */
    public function testEveryLevelASettingDeclaresIsOfferedByAScreen(): void
    {
        $offered = array();

        foreach ( $this->screensBelowTheInstall() as $level => $screens ) {
            foreach ( $screens as $sets ) {
                foreach ( $sets as $set ) {
                    foreach ( (array) ( $set['settings'] ?? array() ) as $key ) {
                        $offered[ $level ][ $set['module'] . '|' . $key ] = true;
                    }
                }
            }
        }

        $this->assertNotEmpty( $offered['profile'] ?? array(), 'the Profile screens offer something' );
        $this->assertNotEmpty( $offered['property'] ?? array(), 'the Property screens offer something' );

        $active  = array_unique( array_merge( array( 'base' ), (array) \OWA\Core\CoreAPI::getActiveModules() ) );
        $missing = array();

        foreach ( $this->settings()->registeredFields() as $id => $args ) {

            list( $module ) = explode( '|', $id, 2 );

            if ( ! in_array( $module, $active, true ) || ! empty( $args['internal'] )
                 || ! \OWA\Module\Base\Classes\Settings::isStorable( (array) $args ) ) {
                continue;
            }

            foreach ( (array) ( $args['scopes'] ?? array() ) as $level ) {

                if ( $level !== 'install' && empty( $offered[ $level ][ $id ] ) ) {
                    $missing[] = str_replace( '|', '.', $id ) . ' at ' . $level;
                }
            }
        }

        $this->assertSame( array(), $missing, sprintf(
            "declared for a level no screen offers: %s\n"
          . 'Offer it on that level\'s screen, or remove the level from its scopes.',
            implode( ', ', $missing ) ) );
    }

    /**
     * Read only at the install, so declared only there: Spam\Score reads the
     * install's spam_detection and spam_threshold, and an event's date parts
     * are filed in the install's timezone. goals keeps the Profile scope its
     * 1.x rows are at.
     */
    public function testInstallOnlySettingsDeclareNoLevelBelowIt(): void
    {
        $c = $this->settings();

        $this->assertSame( array( 'install' ), $c->scopesFor( 'base', 'timezone' ) );
        $this->assertSame( array( 'install' ), $c->scopesFor( 'base', 'spam_detection' ) );
        $this->assertSame( array( 'install' ), $c->scopesFor( 'base', 'spam_threshold' ) );
        $this->assertFalse( $c->isRegistered( 'base', 'log_robots' ), 'spam_threshold replaced it (Update084)' );
        $this->assertSame( array( 'install', 'profile' ), $c->scopesFor( 'base', 'goals' ) );

        foreach ( array( 'announce_visitors', 'notice_email', 'anonymize_ips', 'excluded_ips', 'log_named_users' ) as $key ) {
            $this->assertSame( array( 'install', 'profile' ), $c->scopesFor( 'base', $key ),
                "base.$key is offered on the Profile only" );
        }

        $this->assertSame( array( 'install' ), $c->scopesFor( 'base', 'directory_index_pages' ) );
    }

    /**
     * directory_index_pages is on no screen, at any level, and default_page,
     * which it replaced, is declared nowhere.
     *
     * Every module's fieldsets are registered first: the install screens'
     * register only when the admin nav is built, and a module's only when it
     * is active.
     */
    public function testTheDirectoryIndexListIsOnNoScreen(): void
    {
        foreach ( array( \OWA\Module\Base\Module::class, \OWA\Module\Domstream\Module::class,
                         \OWA\Module\MaxmindGeoip\Module::class, \OWA\Module\Sqs\Module::class,
                         \OWA\Module\Hello\Module::class ) as $class ) {

            $module = new $class();
            $module->registerActions();
            $module->registerAdminPanels();
        }

        $sets = $this->settings()->registeredFieldSets();

        // An install screen's and a Profile screen's, so the loop below has something to check.
        foreach ( array( 'base.tracking', 'base.profileObservation' ) as $id ) {
            $this->assertArrayHasKey( $id, $sets, 'the fieldsets this checks were registered' );
        }

        foreach ( $sets as $id => $set ) {
            foreach ( array( 'directory_index_pages', 'default_page' ) as $key ) {
                $this->assertNotContains( $key, (array) ( $set['settings'] ?? array() ), "fieldset $id offers base.$key" );
            }
        }

        $this->assertArrayHasKey( 'directory_index_pages', $this->baseDeclaration() );
        $this->assertArrayNotHasKey( 'default_page', $this->baseDeclaration() );
        $this->assertArrayNotHasKey( 'default_page', $this->literalDefaults() );
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
