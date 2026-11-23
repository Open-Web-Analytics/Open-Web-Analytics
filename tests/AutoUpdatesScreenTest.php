<?php

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/RestControllerTestCase.php';

use OWA\Module\Base\Classes\AutoUpdate\UpdatesState;
use OWA\Module\Base\Classes\JobQueue;

/**
 * The Updates screen: what it says, from the updates directory and the
 * install marker; the actions it offers, queued as jobs; and its settings.
 */
final class AutoUpdatesScreenTest extends RestControllerTestCase
{
    private const NOW = 1800000000;

    private function state(array $downloaded = [], ?array $failure = null, array $more = []): array
    {
        $more += ['installed' => '2.0.0', 'checkout' => null, 'enabled' => true, 'dirs' => 1, 'marker' => null,
            'window' => ['days' => [6, 7], 'start' => '02:00', 'end' => '06:00', 'timezone' => 'UTC']];

        return UpdatesState::describe($downloaded, $failure, $more['installed'], $more['checkout'], $more['enabled'], $more['dirs'], $more['window'], $more['marker']);
    }

    public function testWhatIsDownloadedAndWhenItInstalls(): void
    {
        $none = $this->state();
        $this->assertSame(['Installed: OWA 2.0.0.', 'Automatic updates are on: a release installs between 02:00 and 06:00 (UTC), Sat, Sun.'], $none['lines']);
        $this->assertSame(['check', 'install'], $none['actions']);

        $this->assertSame('OWA 2.1.0 is downloaded and checked.', end($this->state(['2.1.0', '2.0.0'])['lines']));
        $this->assertCount(2, $this->state(['2.0.0'])['lines'], 'the installed release\'s own tarball is not news');
        $this->assertSame('Automatic updates are on: a release installs as soon as it is downloaded.', $this->state([], null, ['window' => null])['lines'][1]);
    }

    public function testAFailedInstallSaysWhy(): void
    {
        $state = $this->state(['2.1.0', '2.0.0'], ['version' => '2.1.0', 'from' => '2.0.0', 'error' => 'Could not write Core/Env.php. OWA 2.0.0 was put back.']);

        $this->assertSame('Installing OWA 2.1.0 failed: Could not write Core/Env.php. OWA 2.0.0 was put back. Automatic updates skip it until a newer release.', end($state['lines']));
        $this->assertSame(['check', 'install'], $state['actions'], 'the installed release runs: installing it by hand is the way to try again');

        $booted = $this->state([], ['version' => '2.1.0', 'booted' => true, 'error' => 'cmd=core-update-schema did not complete'], ['installed' => '2.1.0']);
        $this->assertSame('OWA 2.1.0 is installed, and finishing it failed: cmd=core-update-schema did not complete. Fix the cause, then run php cli.php cmd=core-update-schema', end($booted['lines']));
    }

    /** While the install marker is there the files may be mixed: the screen says so and offers only a check. */
    public function testAnInstallUnderWayOffersNothingButACheck(): void
    {
        $state = $this->state(['2.1.0'], null, ['marker' => ['from' => '2.0.0', 'to' => '2.1.0', 'phase' => 'copied']]);

        $this->assertSame('OWA 2.1.0 is being installed.', end($state['lines']));
        $this->assertSame(['check'], $state['actions']);
    }

    public function testACheckoutOffersNothing(): void
    {
        $state = $this->state([], null, ['checkout' => '/srv/owa/.git']);

        $this->assertSame('OWA runs from a version control checkout (/srv/owa/.git): it is updated with that, never automatically.', $state['lines'][1]);
        $this->assertSame([], $state['actions']);

        $this->assertStringContainsString('more than one updates-* directory', implode("\n", $this->state([], null, ['dirs' => 2])['lines']));
        $this->assertSame('Automatic updates are off.', $this->state([], null, ['enabled' => false])['lines'][1]);
    }

    /** Each action is a job for the scheduler, one pending per action; anything else is refused. */
    public function testTheActionsAreQueued(): void
    {
        $this->authenticateAs('admin');
        $data = function (string $run): array {
            $c = new \OWA\Module\Base\Controller\UpdatesRun(['run' => $run]);
            $c->action();

            return (array) (new \ReflectionProperty(\OWA\Core\Controller::class, 'data'))->getValue($c);
        };

        $ids    = fn (): array => array_column(JobQueue::listJobs(null, 500), 'id');
        $before = $ids();
        $checks = fn (): int => count(array_filter(JobQueue::listJobs('pending', 500), fn ($j) => $j['command'] === 'core-update'));
        $was    = $checks();

        try {
            $this->assertSame(3314, $data('check')['status_code']);
            $this->assertSame(3314, $data('check')['status_code'], 'clicked twice: still one job');
            $this->assertTrue(JobQueue::isQueued('core-update', 'updates-screen:check'));
            $this->assertSame($was + 1, $checks());

            $this->assertSame(3314, $data('install')['status_code']);
            $this->assertTrue(JobQueue::isQueued('core-update-install', 'updates-screen:install'));

            $this->assertSame(3315, $data('delete-everything')['error_code']);
        } finally {
            foreach (array_diff($ids(), $before) as $id) {
                JobQueue::forget($id);
            }
        }
    }

    public function testThePageRendersTheStateTheActionsAndTheSettings(): void
    {
        $t = new \OWA\Core\Template('base');
        $t->set('settings_page_title', 'Updates');
        $t->set('settings_fieldsets', \OWA\Module\Base\Classes\SettingsForm::pageFieldSets('base.optionsUpdates'));
        $t->set('updates_state', $this->state(['2.1.0', '2.0.0']));
        $this->assertTrue($t->set_template('options_updates.php'));
        $html = $t->fetch();

        $this->assertStringContainsString('OWA 2.1.0 is downloaded and checked.', $html);
        foreach (['check', 'install'] as $run) {
            $this->assertStringContainsString('data-ui="updates:' . $run . '"', $html);
        }
        foreach (['auto_update', 'auto_update_level', 'auto_update_days', 'auto_update_window', 'auto_update_email_success', 'admin_notice_email'] as $name) {
            $this->assertStringContainsString('base.' . $name, $html, $name);
        }
        $this->assertStringNotContainsString('auto_update_releases_url', $html, 'config-only: a web admin cannot repoint the download');
    }

    /** The settings are stored from the screen; the window and days are checked on save; the releases URL is config-only. */
    public function testTheSettings(): void
    {
        $c = \OWA\Core\CoreAPI::configSingleton();

        $this->assertNull($c->valueProblem('base', 'auto_update_window', '02:00-06:00'));
        $this->assertNull($c->valueProblem('base', 'auto_update_window', ''));
        $this->assertNotNull($c->valueProblem('base', 'auto_update_window', '25:00-06:00'));
        $this->assertNull($c->valueProblem('base', 'auto_update_days', 'sat, sun'));
        $this->assertNotNull($c->valueProblem('base', 'auto_update_days', 'weekends'));

        $settings = (require dirname(__DIR__) . '/modules/Base/settings.php')['settings'];
        foreach (['auto_update', 'auto_update_level', 'auto_update_days', 'auto_update_window', 'auto_update_email_success'] as $name) {
            $this->assertTrue($settings[$name]['storable'] ?? false, $name);
        }
        $this->assertArrayNotHasKey('storable', $settings['auto_update_releases_url']);
    }
}
