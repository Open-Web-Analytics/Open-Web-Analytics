<?php

use OWA\Core\ConfigFile;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/SettingsSingletonSnapshot.php';

/**
 * owa-config.php only defines constants.
 *
 * - A file that uses $this outside a comment is not included, and every
 *   request is refused, naming each line and the constant that replaces it.
 * - Every setting only owa-config.php sets has a constant, declared as
 *   `config_constant`. A constant governs its setting: it takes effect, and
 *   a screen that shows the setting shows it fixed.
 * - OWA_ROLE_CAPABILITIES adds capabilities to the roles that exist.
 *
 * No database, and never the real owa-config.php: temp files and sources.
 */
final class ConfigFileConstantsTest extends TestCase
{
    use SettingsSingletonSnapshot;

    /** @var list<string> */
    private $files = [];

    /** @var array<string, mixed> */
    private $private_state = [];

    protected function setUp(): void
    {
        $this->snapshotSettings();

        foreach (['registry', 'pending', 'config_file_problems', 'role_capability_problems'] as $prop) {
            $this->private_state[$prop] = $this->privateProperty($prop)->getValue($this->settings());
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->private_state as $prop => $value) {
            $this->privateProperty($prop)->setValue($this->settings(), $value);
        }

        $this->restoreSettings();

        foreach ($this->files as $file) {
            @unlink($file);
        }
    }

    private function privateProperty(string $name): ReflectionProperty
    {
        return new ReflectionProperty(\OWA\Module\Base\Classes\Settings::class, $name);
    }

    private function tempConfig(string $source): string
    {
        $file = tempnam(sys_get_temp_dir(), 'owa-config-test-');
        file_put_contents($file, $source);
        $this->files[] = $file;

        return $file;
    }

    /** The demo's line, as a 1.x config has it. */
    private const DEMO_GRANT = "\$this->addCapabilityToRole('everyone', array('view_site_list', 'view_reports', 'view_reports_ecommerce'));";

    public function testALiveSetIsRefusedWithItsConstantNamed(): void
    {
        $file = $this->tempConfig("<?php\ndefine('OWA_DB_HOST', 'db.example.com');\n"
            . "\$this->set('base', 'mailer-host', 'smtp.example.com');\n"
            . "\$this->set( 'maxmind_geoip', 'db_license_key', 'owatest-key' );\n");

        $problems = ConfigFile::problems($file);

        $this->assertSame([3, 4], array_column($problems, 'line'));
        $this->assertSame("define( 'OWA_MAILER_HOST', … );", $problems[0]['replacement']);
        $this->assertSame("define( 'OWA_MAXMIND_LICENSE_KEY', … );", $problems[1]['replacement']);

        $text = implode("\n", ConfigFile::messages($problems, $file));

        $this->assertStringContainsString('line 3', $text);
        $this->assertStringContainsString('OWA_MAILER_HOST', $text);
        $this->assertStringNotContainsString('smtp.example.com', $text, 'no value is shown: the file holds credentials');
        $this->assertStringNotContainsString('owatest-key', $text);
    }

    public function testARoleGrantNamesOwaRoleCapabilitiesWithoutTheRetiredCapability(): void
    {
        $problems = ConfigFile::problemsIn("<?php\n" . self::DEMO_GRANT . "\n");

        $this->assertCount(1, $problems);
        $this->assertSame(
            "define( 'OWA_ROLE_CAPABILITIES', [ 'everyone' => [ 'view_site_list', 'view_reports' ] ] );",
            $problems[0]['replacement']);
    }

    public function testSeveralGrantsAreOneConstant(): void
    {
        $problems = ConfigFile::problemsIn("<?php\n" . self::DEMO_GRANT . "\n"
            . "\$this->addCapabilityToRole('analyst', 'edit_sites', true);\n");

        $this->assertStringContainsString(
            "define( 'OWA_ROLE_CAPABILITIES', [ 'everyone' => [ 'view_site_list', 'view_reports' ], 'analyst' => [ 'edit_sites' ] ] );",
            implode("\n", ConfigFile::messages($problems, 'owa-config.php')));
    }

    /** A screen setting and a name OWA does not have get their own advice. */
    public function testASetWithNoConstantSaysWhatToDo(): void
    {
        $problems = ConfigFile::problemsIn("<?php\n\$this->set('base', 'anonymize_ips', true);\n"
            . "\$this->set('base', 'error_handler', 'development');\n");

        $text = ConfigFile::messages($problems, 'owa-config.php');

        $this->assertStringContainsString('settings screen', $text[1]);
        $this->assertStringContainsString('remove it', $text[2]);
    }

    public function testThisInACommentOrAStringIsNotRefused(): void
    {
        $file = $this->tempConfig("<?php\n// \$this->set('base', 'mailer-host', 'x');\n"
            . "# \$this->addCapabilityToRole('everyone', 'view_reports');\n"
            . "/* \$this->set('base', 'ns', 'x'); */\n"
            . "/** \$this */\n"
            . "define('OWA_NOTE', 'use \$this nowhere');\n");

        $this->assertSame([], ConfigFile::problems($file));
    }

    /** The installer's file, written from owa-config-dist.php, is read. */
    public function testTheInstallerWritesAFileWithoutThis(): void
    {
        $this->assertSame([], ConfigFile::problems(__DIR__ . '/../owa-config-dist.php'));

        $file = $this->tempConfig('');
        unlink($file);
        $this->settings()->set('base', 'config_file', $file);

        $this->assertTrue($this->settings()->createConfigFile([
            'db_type' => 'mysql', 'db_name' => 'owatest', 'db_user' => 'owatest', 'db_password' => 'owatest',
            'db_host' => '127.0.0.1', 'db_port' => '3306', 'public_url' => 'https://owa.example.com/',
        ]));

        $this->assertSame([], ConfigFile::problems($file));
    }

    /** Every request type is refused, before anything else is checked. */
    public function testEveryRequestIsRefused(): void
    {
        $messages = ConfigFile::messages(ConfigFile::problemsIn("<?php\n" . self::DEMO_GRANT . "\n"), 'owa-config.php');

        foreach (\OWA\Core\RequestType::ALL as $type) {
            $problem = \OWA\Core\ServerRole::bootProblem($type, [], true, false, $messages);

            $this->assertSame(503, $problem['status'] ?? null, $type);
            $this->assertTrue($problem['php_log'] ?? false, 'also to PHP\'s log: OWA\'s own is named from constants the file did not define');
            $this->assertStringContainsString('OWA_ROLE_CAPABILITIES', $problem['message'], $type);
        }

        $this->assertSame(503, \OWA\Core\ServerRole::bootProblem('ui', [], true, true, $messages)['status'] ?? null,
            'the installer too');
    }

    /** The file that uses $this is not run; configProblems() reports it. */
    public function testTheFileIsNotIncluded(): void
    {
        $file = $this->tempConfig("<?php\ndefine('OWA_CONFIG_FILE_TEST_RAN', true);\n\$this->set('base', 'mailer-host', 'x');\n");

        $this->settings()->set('base', 'config_file', $file);
        (new ReflectionMethod(\OWA\Module\Base\Classes\Settings::class, 'loadConfigFile'))->invoke($this->settings());

        $this->assertFalse(defined('OWA_CONFIG_FILE_TEST_RAN'));
        $this->assertStringContainsString('OWA_MAILER_HOST', implode(' ', $this->settings()->configProblems()));
    }

    /**
     * The boot refusal is the one check. An environment check and an update
     * preflight for the same file could never fail: every request, the CLI and
     * the installer included, is refused before either runs.
     */
    public function testTheBootRefusalIsTheOnlyCheck(): void
    {
        $this->assertFalse(method_exists(\OWA\Module\Base\Classes\EnvironmentCheck::class, 'configFileCode'));
    }

    /** Each declared constant takes effect and governs its setting. */
    public function testEachDeclaredConstantTakesEffect(): void
    {
        $c        = $this->settings();
        $declared = [];

        foreach (\OWA\Core\Module::settingsRegistry()['fields'] as $id => $args) {
            if (!empty($args['config_constant'])) {
                $declared[$args['config_constant']] = $id;
            }
        }

        foreach (['OWA_MAILER_HOST', 'OWA_ERROR_LOG_FILE', 'OWA_ARCHIVE_OLD_EVENTS', 'OWA_CUBE_REBUILD_WINDOW_DAYS',
                  'OWA_MAXMIND_LICENSE_KEY', 'OWA_MAXMIND_WS_USER_NAME', 'OWA_PARTITION_DETAIL_MONTHS'] as $name) {
            $this->assertArrayHasKey($name, $declared);
        }

        $defined = [];

        foreach ($declared as $name => $id) {
            $defined[$name] = 'owatest-' . $name;
        }

        $c->applyDeclaredConstants($defined);

        foreach ($declared as $name => $id) {
            list($module, $key) = explode('|', $id, 2);

            $this->assertSame('owatest-' . $name, $c->get($module, $key), $id);
            $this->assertSame($name, $c->configFileConstantFor($module, $key), $id);
            $this->assertFalse($c->mayPersistInstallWide($module, $key), "$id: nothing may store a value the constant beats");
        }

        $html = \OWA\Module\Base\Classes\SettingsForm::field('maxmind_geoip', 'db_license_key', 'owa_');

        $this->assertStringContainsString('disabled', $html, 'the GeoIP screen shows the key fixed');
        $this->assertStringContainsString('OWA_MAXMIND_LICENSE_KEY', $html);
    }

    public function testRoleCapabilitiesGrantTheDemosListToEveryone(): void
    {
        $c = $this->settings();

        $c->applyRoleCapabilities(['everyone' => ['view_site_list', 'view_reports', 'view_reports_ecommerce']]);

        $this->assertSame([], $c->configProblems());
        $everyone = $c->get('base', 'capabilities')['everyone'];

        $this->assertContains('view_site_list', $everyone);
        $this->assertContains('view_reports', $everyone);
        $this->assertContains('install_schema', $everyone, 'added to, not replaced');
        $this->assertNotContains('view_reports_ecommerce', $everyone, 'a retired capability is ignored');
        $this->assertTrue(\OWA\Core\CoreAPI::isEveryoneCapable('view_reports'));
        $this->assertSame('OWA_ROLE_CAPABILITIES', $c->configFileConstantFor('base', 'capabilities'));
    }

    public function testRoleCapabilitiesRefuseARoleThatDoesNotExist(): void
    {
        $c      = $this->settings();
        $before = $c->get('base', 'capabilities');

        $c->applyRoleCapabilities(['viewer' => ['edit_reports'], 'editors' => ['edit_reports']]);

        $this->assertStringContainsString('editors', implode(' ', $c->configProblems()));
        $this->assertSame($before, $c->get('base', 'capabilities'), 'nothing from the constant is applied');
    }
}
