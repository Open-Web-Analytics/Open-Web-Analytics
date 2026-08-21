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
     * Pins why the config file is the only channel: a stored value is dropped on
     * load. If these ever stopped being config-file-only, a stale path could
     * arrive from the database and the tests above would be guarding nothing.
     */
    public function testBothPathsRemainConfigFileOnly(): void
    {
        $only = \OWA\Module\Base\Classes\Settings::configFileOnlySettings();

        $this->assertArrayHasKey('async_log_dir', $only['base']);
        $this->assertArrayHasKey('error_log_file', $only['base']);

        $stripped = \OWA\Module\Base\Classes\Settings::stripConfigFileOnlySettings([
            'base' => [
                'async_log_dir'  => '/some/previous/server/logs/',
                'error_log_file' => '/some/previous/server/errors.txt',
                'site_id'        => 'kept',
            ],
        ]);

        $this->assertArrayNotHasKey('async_log_dir', $stripped['base']);
        $this->assertArrayNotHasKey('error_log_file', $stripped['base']);
        $this->assertSame('kept', $stripped['base']['site_id']);
    }
}
