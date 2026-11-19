<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/SettingsSingletonSnapshot.php';

/**
 * One source for the config file's path.
 *
 * `isConfigFilePresent()` consulted the `config_file` setting while
 * `loadConfigFile()` built the root's owa-config.php path itself, and
 * `createConfigFile()` built it a third time. So the presence check, the loader
 * and the installer could each be talking about a different file -- an install
 * that writes one config and reads another looks like a config that "did not
 * save", with nothing in the logs.
 *
 * They agree by construction now, and this keeps them agreeing: with the
 * setting pointed somewhere else, all three follow it.
 */
final class ConfigFilePathTest extends TestCase
{
    use SettingsSingletonSnapshot;

    /** @var list<string> */
    private $files = [];

    protected function setUp(): void
    {
        $this->snapshotSettings();
    }

    protected function tearDown(): void
    {
        $this->restoreSettings();

        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    private function elsewhere(): string
    {
        $file = sys_get_temp_dir() . '/owa-config-path-' . bin2hex(random_bytes(6)) . '.php';
        $this->files[] = $file;

        return $file;
    }

    /** isConfigFilePresent() and createConfigFile() take the path from the setting. */
    public function testTheInstallerWritesTheFileTheSettingNames(): void
    {
        $c    = $this->settings();
        $file = $this->elsewhere();
        $c->set('base', 'config_file', $file);

        $this->assertFalse($c->isConfigFilePresent());

        $this->assertTrue($c->createConfigFile([
            'db_type' => 'mysql', 'db_name' => 'owatest', 'db_user' => 'owatest', 'db_password' => 'owatest',
            'db_host' => '127.0.0.1', 'db_port' => '3306', 'public_url' => 'https://owa.example.test/',
        ]));

        $this->assertFileExists($file);
        $this->assertTrue($c->isConfigFilePresent());
    }

    /** loadConfigFile() includes the file the setting names. */
    public function testTheLoaderReadsTheFileTheSettingNames(): void
    {
        $c        = $this->settings();
        $file     = $this->elsewhere();
        $constant = 'CONFIG_PATH_PROBE_' . strtoupper(bin2hex(random_bytes(4)));

        file_put_contents($file, "<?php\ndefine('$constant', true);\n");
        $c->set('base', 'config_file', $file);

        // What loading a file records about it, put back afterwards.
        $state = [];
        $was   = [];

        foreach (['config_file_checked', 'config_file_problems'] as $prop) {
            $state[$prop] = new ReflectionProperty(\OWA\Module\Base\Classes\Settings::class, $prop);
            $was[$prop]   = $state[$prop]->getValue($c);
        }

        try {
            (new ReflectionMethod(\OWA\Module\Base\Classes\Settings::class, 'loadConfigFile'))->invoke($c);
        } finally {
            foreach ($state as $prop => $property) {
                $property->setValue($c, $was[$prop]);
            }
        }

        $this->assertTrue(defined($constant));
    }

    /** The default is still the file a normal install actually uses. */
    public function testTheDefaultIsTheOrdinaryLocation(): void
    {
        $this->assertSame(
            \OWA\Core\Env::root() . '/owa-config.php',
            \OWA\Core\CoreAPI::getSetting( 'base', 'config_file' ) );
    }

    /**
     * The file the setting names is the file that was actually loaded.
     *
     * The tests above prove the three read the setting; this proves the
     * setting names the file whose contents are in effect. Without it a wrong
     * default would satisfy every check above.
     */
    public function testTheLoadedConstantsCameFromTheFileTheSettingNames(): void
    {
        $file = (string) \OWA\Core\CoreAPI::getSetting( 'base', 'config_file' );

        if ( ! file_exists( $file ) ) {

            // CI runs configless -- an install with no config file is a valid
            // state (the wizard has not run yet), and there are no constants to
            // trace back to a file that is not there. The checks above still
            // run, against files of their own.
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
