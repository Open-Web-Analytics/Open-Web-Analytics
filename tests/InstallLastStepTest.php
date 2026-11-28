<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/InstallerHarness.php';
require_once __DIR__ . '/SettingsSingletonSnapshot.php';

/**
 * The installer's last step and what its pages show.
 *
 * - The admin, the first site, the timezone and the install-complete flag are
 *   saved together or not at all (InstallManager::finishInstall()), so a step
 *   that fails part way can be run again.
 * - There is no finish action; base.installBase renders the finish screen.
 * - A nonce is checked against the action that runs, and a step whose nonce
 *   failed is drawn again with what was submitted, but not its password.
 * - The pages anyone can open before OWA is installed show no absolute paths,
 *   and the database form escapes what it shows back.
 * - The CLI install never makes the database password the admin's.
 */
final class InstallLastStepTest extends TestCase
{
    use InstallerHarness;
    use SettingsSingletonSnapshot;

    private const ROOT = __DIR__ . '/..';

    protected function setUp(): void
    {
        $this->snapshotSettings();
        $this->setUpInstaller();
    }

    protected function tearDown(): void
    {
        $this->restoreSettings();
    }

    /** @return array<string, string> the wizard's last form, filled in */
    private function wizardPost(array $over = []): array
    {
        return array_merge([
            'domain'        => 'https://example.test',
            'user_id'       => 'owatest-admin',
            'email_address' => 'owatest-admin@example.test',
            'password'      => 'owatest-admin-password',
            'timezone'      => 'Europe/Berlin',
        ], $over);
    }

    private function source(string $file): string
    {
        return (string) file_get_contents(self::ROOT . '/' . $file);
    }

    public function testAFailedLastStepSavesNothing(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('needs the database');
        }

        $im     = \OWA\Core\CoreAPI::supportClassFactory('base', 'installManager');
        $domain = 'install-rollback-' . bin2hex(random_bytes(4)) . '.example';

        $exists = function () use ($domain): bool {
            $site = \OWA\Core\CoreAPI::entityFactory('base.site');
            $site->load($domain, 'domain');
            return (bool) $site->wasPersisted();
        };

        $write = function () use ($domain): void {
            $site = \OWA\Core\CoreAPI::entityFactory('base.site');
            $site->set('id', $site->generateId('rollback' . $domain));
            $site->set('site_id', 'rollback' . substr(md5($domain), 0, 8));
            $site->set('domain', $domain);
            $this->assertTrue((bool) $site->create(), 'the test row must be written before the failure');
        };

        try {
            // A step that returns no site: rolled back.
            $this->assertFalse($im->finishInstall(function () use ($write) { $write(); return false; }));
            $this->assertFalse($exists(), 'a row written before the failure is rolled back');

            // A step that throws: rolled back.
            $this->assertFalse($im->finishInstall(function () use ($write) { $write(); throw new \RuntimeException('boom'); }));
            $this->assertFalse($exists(), 'a row written before an exception is rolled back');
        } finally {
            \OWA\Core\CoreAPI::dbSingleton()->query(sprintf(
                "DELETE FROM owa_site WHERE domain = '%s'", addslashes($domain)));
        }
    }

    /**
     * The wizard writes the admin, the first site and the timezone inside
     * finishInstall(), which sets the install-complete flag with them, and
     * announces the install once that has committed.
     */
    public function testTheWizardSavesTheLastStepTogetherThenAnnouncesIt(): void
    {
        $this->settings()->set('base', 'install_complete', false);

        $this->installController(\OWA\Module\Base\Controller\InstallBase::class, $this->wizardPost())->action();

        $this->assertSame([
            'installSchema',
            'finishInstall:begin',
            'createAdminUser:owatest-admin',
            'createDefaultSite:https://example.test',
            'persistSetting:base.timezone="Europe\\/Berlin"',
            'finishInstall:end',
            'announceInstallComplete:owatest-admin:owatest-site',
        ], InstallerRecording::$calls);

        $this->assertFalse((bool) $this->settings()->get('base', 'install_complete'),
            'the flag is set by finishInstall() only');
    }

    /** cmd=install does the same, without a timezone of its own. */
    public function testTheCliInstallSavesTheLastStepTogetherThenAnnouncesIt(): void
    {
        $this->settings()->set('base', 'install_complete', false);

        $this->installCli($this->wizardPost())->action();

        $this->assertSame([
            'installSchema',
            'finishInstall:begin',
            'createAdminUser:owatest-admin',
            'createDefaultSite:https://example.test',
            'finishInstall:end',
            'announceInstallComplete:owatest-admin:owatest-site',
        ], InstallerRecording::$calls);

        $this->assertFalse((bool) $this->settings()->get('base', 'install_complete'),
            'the flag is set by finishInstall() only');
    }

    /** A last step that did not commit is not announced, by either installer. */
    public function testALastStepThatDidNotCommitIsNotAnnounced(): void
    {
        $this->im->commits = false;

        $wizard = $this->installController(\OWA\Module\Base\Controller\InstallBase::class, $this->wizardPost());
        $wizard->action();

        $this->assertSame([], $this->recorded('announceInstallComplete'));
        $this->assertStringContainsString('nothing was saved', (string) $wizard->data['error_msg']);

        InstallerRecording::$calls = [];
        $cli = $this->installCli($this->wizardPost());
        $cli->action();

        $this->assertSame([], $this->recorded('announceInstallComplete'));
        $this->assertSame('failed', $cli->getCliOutcome()['outcome']);
    }

    /** base.installBase renders the finish screen; there is no action of its own. */
    public function testThereIsNoFinishAction(): void
    {
        $this->assertEmpty(\OWA\Core\CoreAPI::serviceSingleton()->getMapValue('actions', 'base.installFinish'));
        $this->assertFalse(class_exists('OWA\\Module\\Base\\Controller\\InstallFinish'));
    }

    public function testANonceIsCheckedAgainstTheActionThatRuns(): void
    {
        $c = new \OWA\Module\Base\Controller\InstallStart(
            ['action' => 'base.installConfig', 'do' => 'base.installBase']);

        // The dispatcher runs `action`; a nonce for the `do` beside it is not one for it.
        $this->assertNotTrue($c->verifyNonce(\OWA\Core\CoreAPI::createNonce('base.installBase')));
        $this->assertTrue($c->verifyNonce(\OWA\Core\CoreAPI::createNonce('base.installConfig')));
    }

    /** @return iterable<string, array{class-string, array<string, string>, string, string, string}> */
    public static function nonceSteps(): iterable
    {
        yield 'the defaults step' => [
            \OWA\Module\Base\Controller\InstallBase::class,
            ['action' => 'base.installBase', 'domain' => 'https://example.test', 'user_id' => 'owatest-admin',
             'email_address' => 'owatest-admin@example.test', 'password' => 'owatest-admin-password', 'timezone' => 'Europe/Berlin'],
            'base.installDefaultsEntry', 'defaults', 'password',
        ];
        yield 'the database step' => [
            \OWA\Module\Base\Controller\InstallConfig::class,
            ['action' => 'base.installConfig', 'db_host' => '127.0.0.1', 'db_name' => 'owatest', 'db_user' => 'owatest',
             'db_password' => 'owatest-db-password', 'db_type' => 'mysql', 'public_url' => 'https://owa.example.test/'],
            'base.installConfigEntry', 'config', 'db_password',
        ];
    }

    /**
     * A step posted with an expired or missing nonce is drawn again, with
     * what was submitted except its password and the "no longer valid"
     * message. It does not go to the login form, and runs nothing.
     *
     * @dataProvider nonceSteps
     */
    public function testANonceFailureRedrawsTheStep(string $class, array $post, string $subview, string $var, string $password): void
    {
        // install.php's request type: an earlier test may have left the CLI's, which checks no nonce.
        \OWA\Core\CoreAPI::setSetting('base', 'request_type', \OWA\Core\RequestType::INSTALL);
        $this->settings->present = false;

        foreach (['missing' => null, 'expired' => 'owatest-not-a-nonce'] as $case => $nonce) {
            InstallerRecording::$calls = [];
            $step = $this->installController($class, $nonce === null ? $post : $post + ['nonce' => $nonce]);
            $step->doAction();

            $this->assertSame([], InstallerRecording::$calls, "$case: nothing ran");
            $this->assertSame('base.install', $step->data['view'] ?? null, $case);
            $this->assertSame($subview, $step->data['subview'] ?? null, "$case: the same step");
            $this->assertArrayNotHasKey('redirect_action', $step->data, "$case: not the login form");
            $this->assertNotSame('base.loginForm', $step->data['do'] ?? null, $case);
            $this->assertSame(\OWA\Core\CoreAPI::t('This form is no longer valid'), $step->data['error_msg']['headline'] ?? null, $case);

            $shown = $step->data[$var];
            $this->assertArrayNotHasKey($password, $shown, "$case: the password is not drawn back");
            $expected = $post;
            unset($expected[$password]);
            $this->assertSame($expected, array_intersect_key($shown, $expected), "$case: every other value is");
        }
    }

    /** Only the steps that write check a nonce; the rest draw or redirect whatever is posted. */
    public function testOnlyTheStepsThatWriteNeedANonce(): void
    {
        $needs = [];

        foreach (['InstallStart', 'InstallCheckEnv', 'InstallConfig', 'InstallDefaultsEntry', 'InstallBase'] as $name) {
            $class = '\\OWA\\Module\\Base\\Controller\\' . $name;
            if ((new $class([]))->is_nonce_required) {
                $needs[] = $name;
            }
        }

        $this->assertSame(['InstallConfig', 'InstallBase'], $needs);
    }

    public function testTheDatabaseFormEscapesWhatItShows(): void
    {
        $tpl = $this->source('modules/Base/templates/install_config_entry.php');

        $this->assertDoesNotMatchRegularExpression('/value="<\?php echo /', $tpl,
            'every value on the form goes through $view->out()');
    }

    public function testInstallerPagesShowPathsRelativeToOwa(): void
    {
        $shown = new ReflectionMethod(\OWA\Module\Base\Classes\EnvironmentCheck::class, 'shown');

        $this->assertSame('owa-data/logs/', $shown->invoke(null, \OWA\Core\Env::root() . '/owa-data/logs/', true));
        $this->assertSame('owa-config.php', $shown->invoke(null, \OWA\Core\Env::root() . '/owa-config.php', true));
        $this->assertSame('logs/', $shown->invoke(null, '/srv/elsewhere/data/logs/', true));
        // cmd=instance-info keeps them in full.
        $this->assertSame('/srv/elsewhere/data/logs/', $shown->invoke(null, '/srv/elsewhere/data/logs/', false));

    }

    /**
     * The environment check lists the checks cmd=instance-info runs, with
     * paths relative to OWA's directory, and names the config file by its
     * name alone when the database does not connect.
     */
    public function testTheEnvironmentCheckShowsTheSharedChecksWithoutAbsolutePaths(): void
    {
        $this->im->connects = false;

        $env = $this->installController(\OWA\Module\Base\Controller\InstallCheckEnv::class, []);
        $env->action();

        $config_file = (string) \OWA\Core\CoreAPI::getSetting('base', 'config_file');
        $shared      = \OWA\Module\Base\Classes\EnvironmentCheck::all($config_file, true);
        $checks      = $env->data['checks'];

        $this->assertSame($shared, array_slice($checks, 0, count($shared)));

        $database = $checks[count($shared)];
        $this->assertSame('Database connection', $database['name']);
        $this->assertFalse($database['passed']);
        $this->assertSame('Check the database settings in ' . basename($config_file) . '.', $database['msg']);

        $this->assertStringNotContainsString(\OWA\Core\Env::root(), json_encode($checks, JSON_UNESCAPED_SLASHES));
    }

    /**
     * Once owa-config.php exists the database step has nothing to do: it
     * sends the visitor to the environment check before validating or reading
     * the nonce, so a post never reaches the database with the file's details.
     */
    public function testTheDatabaseStepDoesNotRunOnAnExistingConfig(): void
    {
        $post = [
            'action' => 'base.installConfig', 'nonce' => \OWA\Core\CoreAPI::createNonce('base.installConfig'),
            'db_host' => '127.0.0.1', 'db_name' => 'owatest', 'db_user' => 'owatest', 'db_password' => 'owatest',
            'db_type' => 'mysql', 'public_url' => 'https://owa.example.test/',
        ];

        $step = new class($post) extends \OWA\Module\Base\Controller\InstallConfig {
            public $ran = false;

            public function action()
            {
                $this->ran = true;
            }
        };

        $step->im = $this->im;
        $step->c  = $this->settings;
        $step->doAction();

        $this->assertFalse($step->ran, 'a valid post reaches nothing with a config file in place');
        $this->assertSame('base.installCheckEnv', $step->data['do'] ?? null);
        $this->assertArrayNotHasKey('validation_errors', $step->data);

        // Without one, the same post is the database step's.
        $this->settings->present = false;
        $step->doAction();

        $this->assertTrue($step->ran);
    }

    /**
     * cmd=install takes the admin's password from password= only: none given,
     * none used, whatever the database password is.
     */
    public function testTheCliInstallNeverUsesTheDatabasePassword(): void
    {
        $this->settings()->set('base', 'db_password', 'owatest-db-password');

        $post = $this->wizardPost();
        unset($post['password']);

        $this->installCli($post)->action();

        $this->assertSame([null], $this->im->passwords);
    }

    /**
     * The first Profile's tracking bundle, which the tag on the finish screen
     * loads, is published when the install completes, by the wizard and by
     * cmd=install alike; one that cannot be written then is queued. Before,
     * neither published it and the tag answered 404.
     */
    public function testTheFirstProfileSBundleIsPublishedOrQueued(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('needs the database');
        }

        $db      = \OWA\Core\CoreAPI::dbSingleton();
        $table   = 'owa_job_queue_phpunit_install';
        $siteId  = 'install-bundle-' . bin2hex(random_bytes(4));
        $noBuild = sys_get_temp_dir() . '/owa-install-nobuild-' . bin2hex(random_bytes(4)) . '/';
        mkdir($noBuild, 0700);

        $db->query('DROP TABLE IF EXISTS ' . $table);
        $db->query('CREATE TABLE ' . $table . ' LIKE owa_job_queue');
        \OWA\Module\Base\Classes\JobQueue::$table = $table;
        // No build manifest, so the bundle cannot be written now and is queued.
        \OWA\Module\Base\Classes\TrackerBundle::$buildDir = $noBuild;

        try {
            $im = \OWA\Core\CoreAPI::supportClassFactory('base', 'installManager');

            $this->assertSame($siteId, $im->finishInstall(function () use ($siteId) {
                $site = \OWA\Core\CoreAPI::entityFactory('base.site');
                $site->set('id', $site->generateId($siteId));
                $site->set('site_id', $siteId);
                $site->set('domain', $siteId . '.example');
                $site->create();
                return $siteId;
            }));

            $this->assertTrue(\OWA\Module\Base\Classes\JobQueue::isQueued('publish-trackers', 'publish-trackers:' . $siteId),
                'the first Profile\'s bundle was neither published nor queued');
        } finally {
            \OWA\Module\Base\Classes\TrackerBundle::$buildDir = null;
            \OWA\Module\Base\Classes\JobQueue::$table = null;
            $db->query('DROP TABLE IF EXISTS ' . $table);
            $db->query(sprintf("DELETE FROM owa_site WHERE site_id = '%s'", addslashes($siteId)));
            rmdir($noBuild);
        }
    }
}
