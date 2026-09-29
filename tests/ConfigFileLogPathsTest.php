<?php

use PHPUnit\Framework\TestCase;

/**
 * The config file must actually be able to set the paths reserved to it.
 *
 * error_log_file and async_log_dir are declared config-file-only
 * (Settings::configFileOnlySettings): a value stored in the database is stripped
 * on load, deliberately, so that a path naming a previous server cannot follow a
 * database to a new one. The config file is therefore the ONE place they may be
 * set.
 *
 * It could not set them. loadConfigFile() runs before setupPaths(), and
 * setupPaths() assigned both unconditionally -- so whatever the file said was
 * overwritten moments later, silently. A setting reserved to a channel that
 * cannot write it is unsettable everywhere.
 *
 * WHY IT MATTERS BEYOND TIDINESS
 * async_log_dir is where the event queue lives, and it is derived from the
 * install directory rather than the database. Two installs sharing a directory
 * therefore share a queue no matter how separate their databases are. The
 * self-host e2e runner is exactly that case: it provisions a scratch database
 * but wrote its queue into the live install's owa-data/logs/, so a spec's
 * measurements counted other people's files and its drain consumed their events.
 * That is now fixed by having the harness set async_log_dir -- which requires
 * this.
 *
 * setupPaths() is private and runs during construction, so these drive it by
 * reflection. That is the real method, not a restatement of it: a change that
 * reintroduced the unconditional assignment would fail here.
 */
final class ConfigFileLogPathsTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    private function settings(): \OWA\Module\Base\Classes\Settings
    {
        // A fresh instance, so nothing here disturbs the booted one.
        return new \OWA\Module\Base\Classes\Settings();
    }

    private function runSetupPaths(\OWA\Module\Base\Classes\Settings $s): void
    {
        $m = new \ReflectionMethod($s, 'setupPaths');
        $m->setAccessible(true);
        $m->invoke($s);
    }

    public function testAConfigFileValueForTheQueueDirectorySurvives(): void
    {
        $s = $this->settings();
        $s->set('base', 'async_log_dir', '/tmp/owa-config-chosen-queue/');

        $this->runSetupPaths($s);

        $this->assertSame('/tmp/owa-config-chosen-queue/', $s->get('base', 'async_log_dir'),
            'setupPaths() overwrote a path the config file had already set');
    }

    public function testAConfigFileValueForTheErrorLogSurvives(): void
    {
        $s = $this->settings();
        $s->set('base', 'error_log_file', '/tmp/owa-config-chosen-errors.txt');

        $this->runSetupPaths($s);

        $this->assertSame('/tmp/owa-config-chosen-errors.txt', $s->get('base', 'error_log_file'));
    }

    /**
     * The other half of the contract: an install that sets nothing still gets a
     * working default. Their declared default is '', which is what "nobody set
     * this" means here.
     */
    public function testTheDefaultStillAppliesWhenTheConfigFileSaysNothing(): void
    {
        $s = $this->settings();
        $s->set('base', 'async_log_dir', '');
        $s->set('base', 'error_log_file', '');

        $this->runSetupPaths($s);

        $this->assertSame(OWA_DATA_DIR . 'logs/', $s->get('base', 'async_log_dir'),
            'an install that sets nothing must still get the default queue directory');
        $this->assertStringStartsWith(OWA_DATA_DIR . 'logs/errors_', $s->get('base', 'error_log_file'));
        $this->assertStringEndsWith('.txt', $s->get('base', 'error_log_file'));
    }

    /**
     * Pins why the config file is the only channel.
     *
     * A stored value used to be dropped on load, by a strip that ran over
     * whatever had been fetched. Base declares both of these STATIC now, so
     * the boot query never asks for them and persistSetting() refuses to
     * create one -- there is nothing arriving to drop. If they ever stopped
     * being static, a stale path could arrive from the database and the tests
     * above would be guarding nothing.
     *
     * Two real installs carried async_log_dir values pointing at a previous
     * server's /home/<user>/... paths, which is how this came to be pinned.
     */
    public function testBothPathsRemainConfigFileOnly(): void
    {
        $c = \OWA\Core\CoreAPI::configSingleton();

        $only = \OWA\Module\Base\Classes\Settings::staticSettings();

        foreach (['async_log_dir', 'error_log_file'] as $key) {

            $this->assertArrayHasKey($key, $only['base']);

            $this->assertTrue($c->isRegistered('base', $key),
                sprintf('base.%s must be declared, or nothing constrains it', $key));

            $this->assertFalse($c->mayPersistInstallWide('base', $key),
                sprintf('base.%s must not be storable: a stale path from another '
                      . 'server would override a correct config file', $key));

            $this->assertNotContains($key, (array) ($c->eagerSettings()['base'] ?? []),
                sprintf('base.%s must not be fetched at boot', $key));
        }

        // Discriminating, not a blanket refusal of the module.
        $this->assertTrue($c->mayPersistInstallWide('base', 'log_robots'));
    }
}
