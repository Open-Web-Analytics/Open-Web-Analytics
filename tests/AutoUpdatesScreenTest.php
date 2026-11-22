<?php

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/RestControllerTestCase.php';

use OWA\Module\Base\Classes\AutoUpdate\ReleaseCheck;
use OWA\Module\Base\Classes\AutoUpdate\UpdatesState;
use OWA\Module\Base\Classes\JobQueue;

/**
 * The Updates screen: what it says, from staged.json and install.json; the
 * actions it offers, queued as jobs; and its settings.
 */
final class AutoUpdatesScreenTest extends RestControllerTestCase
{
    private const NOW = 1800000000;

    private function state(?array $staged, ?array $install = null, array $more = []): array
    {
        $more += ['installed' => '2.0.0', 'checkout' => null, 'enabled' => true, 'dirs' => 1, 'present' => true];

        return UpdatesState::describe($staged, $install, $more['installed'], self::NOW, $more['checkout'], $more['enabled'], $more['dirs'], $more['present']);
    }

    private function staged(array $fields = []): array
    {
        return $fields + ['version' => '2.1.0', 'automatic' => true, 'by_hand' => false, 'install_now' => false, 'staged_at' => self::NOW,
            'window' => ['days' => [6, 7], 'start' => '02:00', 'end' => '06:00', 'timezone' => 'UTC']];
    }

    public function testWhatIsStagedAndWhenItInstalls(): void
    {
        $none = $this->state(null);
        $this->assertSame(['Installed: OWA 2.0.0.', 'Automatic updates are on.', 'Nothing is staged.'], $none['lines']);
        $this->assertSame(['check'], $none['actions']);

        $windowed = $this->state($this->staged());
        $this->assertSame('2.1.0 is staged and installs between 02:00 and 06:00 (UTC), Sat, Sun.', $windowed['lines'][2]);
        $this->assertSame(['check', 'now', 'cancel'], $windowed['actions']);

        $now = $this->state($this->staged(['install_now' => true, 'window' => null]));
        $this->assertSame('2.1.0 is staged and installs at the next scheduler run.', $now['lines'][2]);
        $this->assertSame(['check', 'cancel'], $now['actions'], 'nothing to bring forward');
    }

    public function testAStagingThatDoesNotInstallItselfSaysSo(): void
    {
        $hand = $this->state($this->staged(['by_hand' => true, 'automatic' => false, 'staged_at' => self::NOW - 3600]));
        $this->assertSame('2.1.0 was staged by hand. It installs only with: php cli.php cmd=core-update-install', $hand['lines'][2]);
        $this->assertSame('The release check leaves it alone until ' . gmdate('Y-m-d H:i', self::NOW - 3600 + ReleaseCheck::HAND_HOLD) . ' UTC.', $hand['lines'][3]);
        $this->assertSame(['check', 'cancel'], $hand['actions']);

        $waits = $this->state($this->staged(['automatic' => false]));
        $this->assertStringContainsString('2.1.0 is staged but does not install itself', $waits['lines'][2]);

        $past = $this->state($this->staged(), null, ['installed' => '2.1.0']);
        $this->assertSame('2.1.0 is staged, but 2.1.0 is installed: it will not install, and the next release check removes it.', $past['lines'][2]);

        $gone = $this->state($this->staged(), null, ['present' => false]);
        $this->assertStringContainsString('its download is missing', $gone['lines'][2]);
    }

    public function testAnUnfinishedInstallOffersNoCancel(): void
    {
        $state = $this->state($this->staged(), ['version' => '2.1.0', 'attempts' => 2, 'error' => 'lock wait timeout', 'retry_after' => self::NOW + 180]);

        $this->assertSame('An install of 2.1.0 is unfinished: attempt 2 failed (lock wait timeout). It is tried again after '
            . gmdate('Y-m-d H:i', self::NOW + 180) . ' UTC.', end($state['lines']));
        $this->assertSame(['check'], $state['actions'], 'its tree may be half copied');
    }

    public function testACheckoutOffersNothing(): void
    {
        $state = $this->state($this->staged(), null, ['checkout' => '/srv/owa/.git']);

        $this->assertSame('OWA runs from a version control checkout (/srv/owa/.git): it is updated with that, never automatically.', $state['lines'][1]);
        $this->assertSame([], $state['actions']);

        $this->assertStringContainsString('more than one updates-* directory', implode("\n", $this->state(null, null, ['dirs' => 2])['lines']));
        $this->assertSame('Automatic updates are off.', $this->state(null, null, ['enabled' => false])['lines'][1]);
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
        $checks = fn (): int => count(array_filter(JobQueue::listJobs('pending', 500), fn ($j) => $j['command'] === 'core-update-check'));
        $was    = $checks();

        try {
            $this->assertSame(3314, $data('check')['status_code']);
            $this->assertSame(3314, $data('check')['status_code'], 'clicked twice: still one job');
            $this->assertTrue(JobQueue::isQueued('core-update-check', 'updates-screen:check'));
            $this->assertSame($was + 1, $checks());

            $this->assertSame(3314, $data('now')['status_code']);
            $this->assertTrue(JobQueue::isQueued('core-update-ignore-window', 'updates-screen:now'));

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
        $t->set('updates_state', $this->state($this->staged()));
        $this->assertTrue($t->set_template('options_updates.php'));
        $html = $t->fetch();

        $this->assertStringContainsString('2.1.0 is staged and installs between 02:00 and 06:00', $html);
        foreach (['check', 'now', 'cancel'] as $run) {
            $this->assertStringContainsString('data-ui="updates:' . $run . '"', $html);
        }
        foreach (['auto_update', 'auto_update_level', 'auto_update_patch_immediately', 'auto_update_days', 'auto_update_window', 'auto_update_email_success', 'admin_notice_email'] as $name) {
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
        foreach (['auto_update', 'auto_update_level', 'auto_update_patch_immediately', 'auto_update_days', 'auto_update_window', 'auto_update_email_success'] as $name) {
            $this->assertTrue($settings[$name]['storable'] ?? false, $name);
        }
        $this->assertArrayNotHasKey('storable', $settings['auto_update_releases_url']);
    }
}
