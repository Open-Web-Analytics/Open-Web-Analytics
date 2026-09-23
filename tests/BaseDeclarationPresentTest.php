<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Base ships a settings declaration, and the release does not go out without
 * one.
 *
 * This is a load-bearing file, not documentation. Without it Base falls back
 * into the boot query's wholesale clause and every row it owns is loaded again
 * -- including error_log_file and report_wrapper, where a stored value is an
 * arbitrary file write and an arbitrary file include.
 *
 * Today a runtime strip in load() still catches that case, which is why the
 * file's absence is survivable rather than dangerous. That strip is meant to
 * go -- it states a rule the declaration already states, and one rule enforced
 * in two places acquires two slightly different definitions -- and this test is
 * what has to be in place before it can.
 *
 * So the file's absence has to be loud. Once the strip goes it is the one
 * failure that would otherwise look like nothing at all: an install that boots,
 * serves, and quietly reads settings it should never read.
 */
final class BaseDeclarationPresentTest extends TestCase
{
    private const FILE = OWA_DIR . 'modules/Base/settings.php';

    public function testTheFileExists(): void
    {
        $this->assertFileExists( self::FILE,
            'Base must ship modules/Base/settings.php. Without it Base rejoins the '
          . 'wholesale boot clause and its config-file-only rows load again.' );
    }

    public function testItParsesAndNamesItself(): void
    {
        $decl = include self::FILE;

        $this->assertIsArray( $decl, 'the declaration must return an array' );

        $this->assertSame( 'base', $decl['module'] ?? null,
            'the file names its own module -- core cannot derive it, because '
          . 'Lib::moduleDirName() maps several runtime names onto one directory' );

        $this->assertIsArray( $decl['settings'] ?? null );
        $this->assertNotEmpty( $decl['settings'], 'an empty declaration is the same '
          . 'failure as a missing file, and must fail the same way' );
    }

    /** And the running install actually picked it up. */
    public function testBaseIsAmongTheDeclaredModules(): void
    {
        $registry = \OWA\Core\Module::settingsRegistry();

        $this->assertArrayHasKey( 'base', (array) $registry['declared'],
            'the file exists but Module::settingsRegistry() did not read it -- check '
          . 'it returns a module name and a settings array' );
    }

    /**
     * The guarantee that will replace the strip: nothing config-file-only can
     * be stored, because the declaration says it is static.
     */
    public function testConfigFileOnlySettingsAreUnstorableThroughTheDeclaration(): void
    {
        $c = \OWA\Core\CoreAPI::configSingleton();

        foreach ( array_keys(
            (array) ( \OWA\Module\Base\Classes\Settings::configFileOnlySettings()['base'] ?? array() ) )
            as $key ) {

            $this->assertTrue( $c->isRegistered( 'base', $key ), sprintf(
                'base.%s is config-file-only but the declaration does not mention it, '
              . 'so nothing constrains it', $key ) );

            $this->assertFalse( $c->mayPersistInstallWide( 'base', $key ), sprintf(
                'base.%s must be unstorable', $key ) );
        }
    }
}
