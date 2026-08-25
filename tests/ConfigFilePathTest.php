<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * One source for the config file's path.
 *
 * `isConfigFilePresent()` consulted the `config_file` setting while
 * `loadConfigFile()` built OWA_DIR.'owa-config.php' itself, and
 * `createConfigFile()` built it a third time. So the presence check, the loader
 * and the installer could each be talking about a different file -- an install
 * that writes one config and reads another looks like a config that "did not
 * save", with nothing in the logs.
 *
 * They agree by construction now, and this keeps them agreeing: the check is on
 * the SOURCE, because a test that merely compared two paths would pass the day
 * someone hardcodes the same default in a fourth place.
 */
final class ConfigFilePathTest extends TestCase
{
    private function settingsSource(): string
    {
        return (string) file_get_contents(
            OWA_DIR . 'modules/Base/Classes/Settings.php' );
    }

    public function testTheSettingIsWhatTheLoaderAndTheInstallerBothRead(): void
    {
        $src = $this->settingsSource();

        // The three call sites that decide WHICH file, all reading the setting.
        $this->assertSame( 3, preg_match_all(
            "/\\\$this->get\(\s*'base'\s*,\s*'config_file'\s*\)/", $src ),
            'isConfigFilePresent(), loadConfigFile() and createConfigFile() must '
            . 'each take the path from the setting, not build it' );
    }

    public function testNothingInSettingsBuildsThePathItself(): void
    {
        $src = $this->settingsSource();

        // Strip comments first: this file discusses owa-config.php constantly,
        // and a naive scan would match the prose rather than the code.
        $code = preg_replace( '~/\*.*?\*/|//[^\n]*~s', '', $src );

        $this->assertSame( 1, preg_match_all(
            "/OWA_DIR\s*\.\s*'owa-config\.php'/", $code ),
            'only the `config_file` default may name the file; every other use '
            . 'must go through the setting' );
    }

    /** The default is still the file a normal install actually uses. */
    public function testTheDefaultIsTheOrdinaryLocation(): void
    {
        $this->assertSame(
            OWA_DIR . 'owa-config.php',
            \OWA\Core\CoreAPI::getSetting( 'base', 'config_file' ) );
    }

    /**
     * The file the setting names is the file that was actually loaded.
     *
     * Source scanning proves the call sites read the setting; this proves the
     * setting names the file whose contents are in effect. Without it a wrong
     * default would satisfy every check above.
     */
    public function testTheLoadedConstantsCameFromTheFileTheSettingNames(): void
    {
        $file = (string) \OWA\Core\CoreAPI::getSetting( 'base', 'config_file' );

        if ( ! file_exists( $file ) ) {

            // CI runs configless -- an install with no config file is a valid
            // state (the wizard has not run yet), and there are no constants to
            // trace back to a file that is not there. The three checks above
            // still run, because they read the source rather than the install.
            $this->markTestSkipped( 'no config file on this install' );
        }

        $this->assertTrue( defined( 'OWA_DB_NAME' ),
            'the config file was loaded, so its constants are defined' );

        $this->assertMatchesRegularExpression(
            "/define\\s*\\(\\s*['\"]OWA_DB_NAME['\"]\\s*,\\s*['\"]"
                . preg_quote( (string) OWA_DB_NAME, '/' ) . "['\"]/",
            (string) file_get_contents( $file ),
            'the database in effect must be the one written in the file the '
            . 'setting names -- if these differ, the loader read a different file' );
    }
}
