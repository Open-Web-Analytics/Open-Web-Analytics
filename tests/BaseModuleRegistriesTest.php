<?php

use PHPUnit\Framework\TestCase;

/**
 * Base's registries are exactly what the hand-written registration calls in
 * Module.php made before they moved to modules/Base/config/*.php.
 *
 * fixtures/base_module_registries.json was captured from those calls (v2
 * d2ac3faf): the action map, the CLI commands, the REST routes, the jobs (name,
 * command, schedule under the public_url below, params, description), the
 * admin panels, the settings fieldsets and the report nav. Each is compared
 * whole, in order, so an entry added, dropped or changed in a config file
 * fails here until the fixture is updated with it.
 */
final class BaseModuleRegistriesTest extends TestCase
{
    /** The public_url the fixture's spread schedules were derived from. */
    private const SEED_URL = 'https://owatest.example.com/';

    /** @var array<string, mixed> */
    private static $captured;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';

        self::$captured = self::capture();
    }

    /**
     * Base's registrations made into empty registries, which are put back
     * afterwards.
     *
     * @return array<string, mixed>
     */
    private static function capture(): array
    {
        $s        = \OWA\Core\CoreAPI::serviceSingleton();
        $settings = \OWA\Core\CoreAPI::configSingleton();
        $fs       = new ReflectionProperty(get_class($settings), 'fieldsets');

        $actions   = $s->getMap('actions');
        $routes    = $s->restApiRoutes;
        $fieldsets = $fs->getValue($settings);
        $url       = \OWA\Core\CoreAPI::getSetting('base', 'public_url');

        try {
            \OWA\Core\CoreAPI::setSetting('base', 'public_url', self::SEED_URL);
            $s->setMap('actions', []);
            $s->restApiRoutes = [];
            $fs->setValue($settings, []);

            $m = new \OWA\Module\Base\Module();
            $m->registerAdminPanels();
            $m->registerNavigation();

            return [
                'actions'         => $s->getMap('actions'),
                'cli_commands'    => $m->cli_commands,
                'rest_routes'     => $s->getAllRestApiRoutes(),
                'jobs'            => $m->scheduled_jobs,
                'admin_panels'    => $m->getAdminPanels(),
                'fieldsets'       => $fs->getValue($settings),
                'navigation'      => $m->getNavigationLinks(),
            ];
        } finally {
            \OWA\Core\CoreAPI::setSetting('base', 'public_url', $url);
            $s->setMap('actions', $actions);
            $s->restApiRoutes = $routes;
            $fs->setValue($settings, $fieldsets);
        }
    }

    /** @return array<string, mixed> */
    private static function fixture(): array
    {
        return json_decode((string) file_get_contents(__DIR__ . '/fixtures/base_module_registries.json'), true);
    }

    /** @return array<string, array{string}> */
    public static function registries(): array
    {
        $out = [];

        foreach (['actions', 'cli_commands', 'rest_routes', 'jobs', 'admin_panels', 'fieldsets', 'navigation'] as $name) {
            $out[$name] = [$name];
        }

        return $out;
    }

    /**
     * @dataProvider registries
     */
    public function testTheRegistryIsUnchanged(string $name): void
    {
        $fixture = self::fixture();

        $this->assertArrayHasKey($name, $fixture);
        $this->assertSame($fixture[$name], self::$captured[$name]);
    }

    /** The fixture covers what Base registers: not an empty capture compared with an empty one. */
    public function testTheFixtureIsNotEmpty(): void
    {
        $fixture = self::fixture();

        $this->assertCount(129, $fixture['actions']);
        $this->assertCount(41, $fixture['cli_commands']);
        $this->assertSame(12, array_sum(array_map('count', $fixture['rest_routes']['base']['v1'])), 'REST routes');
        $this->assertCount(8, $fixture['jobs']);
        $this->assertCount(6, $fixture['admin_panels']);
        $this->assertCount(19, $fixture['fieldsets']);
        $this->assertCount(8, $fixture['navigation']['Reports']);
    }
}
