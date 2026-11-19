<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The installer's last step and what its pages show.
 *
 * - The admin, the first site, the timezone and the install-complete flag are
 *   saved together or not at all (InstallManager::finishInstall()), so a step
 *   that fails part way can be run again.
 * - The finish page writes nothing; base.installBase renders it.
 * - A nonce is checked against the action that runs.
 * - The pages anyone can open before OWA is installed show no absolute paths,
 *   and the database form escapes what it shows back.
 * - The CLI install never makes the database password the admin's.
 */
final class InstallLastStepTest extends TestCase
{
    private const ROOT = __DIR__ . '/..';

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

    public function testBothInstallersSaveTheLastStepTogether(): void
    {
        $this->assertStringContainsString('->finishInstall(', $this->source('modules/Base/Controller/InstallBase.php'));
        $this->assertStringContainsString('->finishInstall(', $this->source('modules/Base/Controller/InstallCli.php'));

        foreach (['InstallBase', 'InstallCli'] as $c) {
            $this->assertStringNotContainsString("persistSetting('base', 'install_complete'",
                $this->source("modules/Base/Controller/$c.php"), "$c sets the flag only through finishInstall()");
        }
    }

    public function testTheFinishPageWritesNothing(): void
    {
        $src = $this->source('modules/Base/Controller/InstallFinish.php');

        $this->assertStringNotContainsString('persistSetting', $src);
        $this->assertStringNotContainsString('->save()', $src);
    }

    public function testANonceIsCheckedAgainstTheActionThatRuns(): void
    {
        $c = new \OWA\Module\Base\Controller\InstallStart(
            ['action' => 'base.installConfig', 'do' => 'base.installBase']);

        // The dispatcher runs `action`; a nonce for the `do` beside it is not one for it.
        $this->assertNotTrue($c->verifyNonce(\OWA\Core\CoreAPI::createNonce('base.installBase')));
        $this->assertTrue($c->verifyNonce(\OWA\Core\CoreAPI::createNonce('base.installConfig')));
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

        $env = $this->source('modules/Base/Controller/InstallCheckEnv.php');
        $this->assertStringContainsString('EnvironmentCheck::all( $config_file, true )', $env);
        $this->assertStringNotContainsString("'Check the database settings in ' . \$config_file", $env);
    }

    /**
     * Once owa-config.php exists the database step has nothing to do: it
     * sends the visitor to the environment check before validating or reading
     * the nonce, so a post never reaches the database with the file's details.
     */
    public function testTheDatabaseStepDoesNotRunOnAnExistingConfig(): void
    {
        $src = $this->source('modules/Base/Controller/InstallConfig.php');
        $fn  = substr($src, (int) strpos($src, 'function doAction('));
        $fn  = substr($fn, 0, (int) strpos($fn, "\n    }\n"));

        $this->assertStringContainsString('isConfigFilePresent()', $fn);
        $this->assertStringContainsString("setRedirectAction( 'base.installCheckEnv' )", $fn);
        $this->assertLessThan(strpos($fn, 'parent::doAction()'), strpos($fn, 'isConfigFilePresent()'));
    }

    public function testTheCliInstallNeverUsesTheDatabasePassword(): void
    {
        $this->assertStringNotContainsString("get('base', 'db_password')",
            $this->source('modules/Base/Controller/InstallCli.php'));
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
