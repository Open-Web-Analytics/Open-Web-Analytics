<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/fixtures/TestFixtureModule.php';

/**
 * Core\Module's *FromConfig() registration: a module's config/<name>.php
 * lists what it registers, and each entry goes through the same register*()
 * call a hand-written line would make.
 */
final class ModuleRegistrationConfigTest extends TestCase
{
    /** @var string */
    private $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/owa-regconf-' . bin2hex(random_bytes(4)) . '/';

        mkdir($this->dir . 'config', 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . 'config/*.php') as $file) {
            unlink($file);
        }

        @rmdir($this->dir . 'config');
        @rmdir($this->dir);
    }

    /** A module object whose path is the scratch directory. */
    private function module()
    {
        $module = (new ReflectionClass(\OWA\Module\TestFixture\Module::class))
            ->newInstanceWithoutConstructor();

        $module->name = \OWA\Module\TestFixture\Module::NAME;

        $p = new ReflectionProperty(\OWA\Core\Module::class, 'path');
        $p->setValue($module, $this->dir);

        return $module;
    }

    private function write(string $name, string $php): void
    {
        file_put_contents($this->dir . 'config/' . $name . '.php', "<?php\n" . $php . "\n");
    }

    private function call($module, string $method, ...$args)
    {
        return (new ReflectionMethod(\OWA\Core\Module::class, $method))->invoke($module, ...$args);
    }

    public function testAMissingFileRegistersNothing(): void
    {
        $this->assertSame([], $this->call($this->module(), 'registrationConfig', 'cli_commands'));
    }

    public function testAFileThatReturnsNoArrayRegistersNothing(): void
    {
        $this->write('cli_commands', "return 'update';");

        $m = $this->module();
        $this->call($m, 'registerCliCommandsFromConfig');

        $this->assertSame([], $m->cli_commands);
    }

    public function testTheFileCannotReachTheModule(): void
    {
        $this->write('cli_commands', "return [ 'probe' => isset( \$this ) ? 'reached' : 'not reached' ];");

        $m = $this->module();
        $this->call($m, 'registerCliCommandsFromConfig');

        $this->assertSame(['probe' => 'not reached'], $m->cli_commands);
    }

    public function testActionsAreRegisteredInTheActionMap(): void
    {
        $this->write('actions', "return [ 'test_fixture.probeAction' => 'OWA\\\\Module\\\\TestFixture\\\\Probe' ];");

        $s = \OWA\Core\CoreAPI::serviceSingleton();

        try {
            $this->call($this->module(), 'registerActionsFromConfig');

            $this->assertSame(['class_name' => 'OWA\\Module\\TestFixture\\Probe'],
                $s->getMapValue('actions', 'test_fixture.probeAction'));
        } finally {
            $map = $s->getMap('actions');
            unset($map['test_fixture.probeAction']);
            $s->setMap('actions', $map);
        }
    }

    public function testRestRoutesKeepTheirConfiguration(): void
    {
        $this->write('rest_routes', "return [ 'v1' => [ 'probes' => [
            'GET'    => [ 'class' => 'ProbesRest' ],
            'DELETE' => [ 'class' => 'ProbeDeleteRest', 'conf' => [ 'params_order' => [ 'probe_id' ] ] ],
        ] ] ];");

        $s      = \OWA\Core\CoreAPI::serviceSingleton();
        $routes = $s->restApiRoutes;

        try {
            $this->call($this->module(), 'registerRestApiRoutesFromConfig');

            $this->assertSame([
                'GET'    => ['class_name' => 'ProbesRest', 'conf' => []],
                'DELETE' => ['class_name' => 'ProbeDeleteRest', 'conf' => ['params_order' => ['probe_id']]],
            ], $s->getAllRestApiRoutes()['test_fixture']['v1']['probes']);
        } finally {
            $s->restApiRoutes = $routes;
        }
    }

    /** A spread is derived from jobSeed() with the job's own name, as a registerJob() line derives it. */
    public function testJobSchedulesAreFixedOrSpreadPerJob(): void
    {
        $this->write('jobs', "return [
            'fixed'    => [ 'command' => 'probe', 'schedule' => '* * * * *', 'params' => [ 'scheduled' => 1 ], 'description' => 'Probes.' ],
            'daily'    => [ 'command' => 'probe', 'schedule' => [ 'spread' => 'daily' ] ],
            'weekly'   => [ 'command' => 'probe', 'schedule' => [ 'spread' => 'weekly' ] ],
            'minutely' => [ 'command' => 'probe', 'schedule' => [ 'spread' => 'minutely', 'interval' => 5 ] ],
        ];");

        $m = $this->module();
        $this->call($m, 'registerJobsFromConfig');

        $seed = fn($job) => $this->call($m, 'jobSeed', $job);

        $this->assertSame([
            'name'        => 'fixed',
            'command'     => 'probe',
            'schedule'    => '* * * * *',
            'params'      => ['scheduled' => 1],
            'description' => 'Probes.',
            'module'      => 'test_fixture',
            'source'      => 'code',
        ], $m->scheduled_jobs['fixed']);

        $this->assertSame(\OWA\Core\Cron::dailySpreadFor($seed('daily')), $m->scheduled_jobs['daily']['schedule']);
        $this->assertSame(\OWA\Core\Cron::weeklySpreadFor($seed('weekly')), $m->scheduled_jobs['weekly']['schedule']);
        $this->assertSame(\OWA\Core\Cron::minutelySpreadFor($seed('minutely'), 5), $m->scheduled_jobs['minutely']['schedule']);
        $this->assertSame([], $m->scheduled_jobs['daily']['params']);
        $this->assertSame('', $m->scheduled_jobs['daily']['description']);
    }

    public function testAJobWithoutACommandOrAKnownScheduleIsNotRegistered(): void
    {
        $this->write('jobs', "return [
            'no-command'   => [ 'schedule' => '@daily' ],
            'no-schedule'  => [ 'command' => 'probe' ],
            'odd-spread'   => [ 'command' => 'probe', 'schedule' => [ 'spread' => 'fortnightly' ] ],
            'kept'         => [ 'command' => 'probe', 'schedule' => 'off' ],
        ];");

        $m = $this->module();
        $this->call($m, 'registerJobsFromConfig');

        $this->assertSame(['kept'], array_keys($m->scheduled_jobs));
    }

    public function testAdminPanelsRegisterPagesAndFieldsets(): void
    {
        $this->write('admin_panels', "return [
            'pages'     => [ [ 'do' => 'test_fixture.options', 'title' => 'Probe', 'order' => 2, 'fieldsets' => [ 'test_fixture.probe' ] ] ],
            'fieldsets' => [ [ 'id' => 'probe', 'legend' => 'Probe', 'settings' => [ 'words' ] ] ],
        ];");

        $settings  = \OWA\Core\CoreAPI::configSingleton();
        $fs        = new ReflectionProperty(get_class($settings), 'fieldsets');
        $fieldsets = $fs->getValue($settings);

        try {
            $m = $this->module();
            $this->call($m, 'registerAdminPanelsFromConfig');

            $page = $m->getAdminPanels()['test_fixture.options'];

            $this->assertSame('Probe', $page['anchortext']);
            $this->assertSame(['test_fixture.probe'], $page['fieldsets']);
            $this->assertTrue($page['owa_titled']);

            $set = $settings->registeredFieldSets()['test_fixture.probe'];

            $this->assertSame(['words'], $set['settings']);
            $this->assertSame('test_fixture', $set['module']);
        } finally {
            $fs->setValue($settings, $fieldsets);
        }
    }

    public function testNavigationTakesAReportIdOrLinkParamsAndTheDefaults(): void
    {
        $this->write('navigation', "return [ 'Reports' => [
            'Probe' => [
                'report'     => 'probe-report',
                'anchortext' => 'Probe',
                'order'      => 3,
                'links'      => [
                    [ 'ref' => [ 'do' => 'test_fixture.list' ], 'anchortext' => 'List', 'priviledge' => 'view_site_list' ],
                ],
            ],
        ] ];");

        $m = $this->module();
        $this->call($m, 'registerNavigationFromConfig');

        $this->assertSame([
            'Reports' => [
                'Probe' => [
                    'ref'        => ['do' => 'base.report', 'reportId' => 'probe-report'],
                    'anchortext' => 'Probe',
                    'order'      => 3,
                    'priviledge' => 'view_reports',
                    'icon_class' => \OWA\Core\Module::DEFAULT_NAV_ICON,
                    'subgroup'   => [[
                        'ref'        => ['do' => 'test_fixture.list'],
                        'anchortext' => 'List',
                        'order'      => null,
                        'priviledge' => 'view_site_list',
                        'icon_class' => '',
                    ]],
                ],
            ],
        ], $m->getNavigationLinks());
    }
}
