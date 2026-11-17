<?php

use PHPUnit\Framework\TestCase;

/**
 * Every core controller must be registered against an action name.
 *
 * CoreAPI::performAction() has two branches. The registered one hands the class
 * name from the action map to Lib::simpleFactory(). The unregistered
 * one falls through to moduleFactory(), which reconstructs a class name AND a
 * filesystem path by concatenating the request's own 'do' param.
 *
 * That legacy branch has to stay: third-party modules resolve through it, and
 * removing it would break them. It is now guarded by an identifier check (see
 * ActionNameValidationTest), but core should never depend on it.
 *
 * Before this, exactly ONE action was registered out of ~140 controllers, so in
 * practice every request took the legacy branch. This test is what stops that
 * drifting back: add a controller without registering it and the count check
 * fails.
 */
final class ActionRegistryTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    private function actionMap(): array
    {
        $map = \OWA\Core\CoreAPI::serviceSingleton()->getMap('actions');

        return is_array($map) ? $map : [];
    }

    public function testTheActionMapIsPopulated(): void
    {
        /*
         * Derived from the tree rather than a round number.
         *
         * It was `> 100`, chosen when there were about 153 actions. Converting
         * the reports to configuration retired 53 of them and left exactly 100,
         * so the guard failed for a reason that was not a regression -- and the
         * obvious repair, picking a smaller number, is how a floor like this
         * rots until it stops detecting anything.
         *
         * The map covers every module, so it can only be larger than Base's own
         * dispatchable controllers. If module registration ever fails to run,
         * the count collapses well below this and the check fires -- which is
         * the state this test exists to catch.
         */
        $floor = count($this->dispatchableBaseControllers());

        $this->assertGreaterThan(1, $floor, 'no controllers found, so this proves nothing');

        $this->assertGreaterThanOrEqual($floor, count($this->actionMap()),
            'The action registry does not even cover Base, so requests are resolving '
            . 'through the legacy path.');
    }

    /** Base controllers that can be reached as a 'do' action. */
    private function dispatchableBaseControllers(): array
    {
        $out = [];

        foreach (glob(\OWA\Core\Env::root() . '/modules/Base/Controller/*.php') as $file) {
            $short = basename($file, '.php');

            if (in_array($short, $this->exempt(), true)) {
                continue;
            }

            $class = 'OWA\\Module\\Base\\Controller\\' . $short;

            if (class_exists($class) && (new \ReflectionClass($class))->isAbstract()) {
                continue;
            }

            $out[] = $short;
        }

        return $out;
    }

    /**
     * Controllers that are deliberately NOT reachable as a 'do' action, so are
     * not expected in the map.
     */
    private function exempt(): array
    {
        return [
            // Reached as a CLI command, registered via registerCliCommand().
            'Cli',
            'Install',
        ];
    }

    public function testEveryBaseControllerIsRegistered(): void
    {
        $map = $this->actionMap();

        $registered = [];
        foreach ($map as $action => $meta) {
            $registered[ $meta['class_name'] ] = $action;
        }

        $missing = [];
        foreach ($this->dispatchableBaseControllers() as $short) {

            $class = 'OWA\\Module\\Base\\Controller\\' . $short;

            if (! isset($registered[$class])) {
                $missing[] = $short;
            }
        }

        $this->assertSame([], $missing,
            "These Base controllers have no registered action, so they resolve through the "
            . "legacy concatenation path: " . implode(', ', $missing));
    }

    public function testRegisteredClassesActuallyExist(): void
    {
        // A typo in a registration would only surface at runtime, on whichever
        // report page happens to use that action.
        $broken = [];

        foreach ($this->actionMap() as $action => $meta) {
            $class = $meta['class_name'];

            if (! class_exists($class)) {
                $broken[] = $action . ' -> ' . $meta['class_name'];
            }
        }

        $this->assertSame([], $broken,
            'Registered actions point at classes that do not exist: ' . implode(', ', $broken));
    }

    /**
     * REST routes are a SECOND registration map, and nothing checked it.
     *
     * registerRestApiRoute() stores its own class name in
     * $restApiRoutes -- not in the action map -- so
     * testRegisteredClassesActuallyExist() above does not see it. A controller
     * registered in both places (every REST controller here) can be renamed in
     * one and left stale in the other, and the action-map test still passes.
     * The break then surfaces only when a client actually calls that endpoint.
     */
    public function testRegisteredRestRouteClassesActuallyExist(): void
    {
        $routes = \OWA\Core\CoreAPI::serviceSingleton()->getAllRestApiRoutes();

        $this->assertNotEmpty($routes, 'no REST API routes registered at all');

        $broken = [];
        $seen   = 0;

        foreach ($routes as $module => $versions) {
            foreach ($versions as $version => $names) {
                foreach ($names as $name => $methods) {
                    foreach ($methods as $method => $meta) {

                        $seen++;
                        $where = sprintf('%s %s/%s/%s', $method, $module, $version, $name);

                        $class = $meta['class_name'] ?? '';

                        if (! $class || ! class_exists($class)) {
                            $broken[] = $where . ' -> class ' . ($meta['class_name'] ?? '(none)');
                        }
                    }
                }
            }
        }

        $this->assertGreaterThan(1, $seen, 'only one REST route seen -- the map did not load');

        $this->assertSame([], $broken,
            'REST routes point at a class that does not exist: ' . implode(', ', $broken));
    }

    /**
     * A registered action is dispatched to its class: simpleFactory() builds
     * the controller the map names, and a file path a module still passes is
     * not part of that.
     */
    public function testARegisteredActionBuildsItsController(): void
    {
        $base = $this->actionMap()['base.addSiteRest'] ?? null;
        $this->assertNotNull($base);
        $this->assertSame(\OWA\Module\Base\Controller\AddSiteRest::class, $base['class_name']);

        $this->assertInstanceOf(\OWA\Module\Base\Controller\AddSiteRest::class,
            \OWA\Core\Lib::simpleFactory($base['class_name'], '', []));

        $this->assertInstanceOf(\OWA\Module\Base\Controller\AddSiteRest::class,
            \OWA\Core\Lib::simpleFactory($base['class_name'], 'Controller/NoSuchFile.php', []),
            'a path passed by an older caller is ignored');
    }

    /** A module written before PSR-4 still passes a class file path; it is accepted and ignored. */
    public function testAFilePathPassedByAnOlderModuleIsIgnored(): void
    {
        $module = new class extends \OWA\Core\Module {
            public function __construct() {
                $this->name = 'zz_legacy_paths';
            }
            public function register() {
                $this->registerAction('zz_legacy_paths.thing', \OWA\Module\Base\Controller\AddSiteRest::class, 'Controller/AddSiteRest.php');
                $this->registerRestApiRoute('v1', 'zzThings', 'GET', \OWA\Module\Base\Controller\AddSiteRest::class,
                    'Controller/AddSiteRest.php', ['params_order' => ['thing_id']]);
                $this->registerImplementation('zz_legacy_types', 'thing', \OWA\Module\Base\Classes\FileEventQueue::class,
                    'Classes/FileEventQueue.php', ['x' => 1]);
            }
        };

        $s       = \OWA\Core\CoreAPI::serviceSingleton();
        $actions = $s->getMap('actions');
        $routes  = $s->restApiRoutes;

        try {
            $module->register();

            $this->assertSame(['class_name' => \OWA\Module\Base\Controller\AddSiteRest::class],
                $s->getMapValue('actions', 'zz_legacy_paths.thing'));
            $this->assertSame(['class_name' => \OWA\Module\Base\Controller\AddSiteRest::class, 'conf' => ['params_order' => ['thing_id']]],
                $s->getRestApiRoute('zz_legacy_paths', 'v1', 'zzThings', 'GET'));
            $this->assertSame([\OWA\Module\Base\Classes\FileEventQueue::class, '', ['x' => 1]],
                $s->getMapValue('zz_legacy_types', 'thing'));
        } finally {
            $s->setMap('actions', $actions);
            $s->setMap('zz_legacy_types', []);
            $s->restApiRoutes = $routes;
        }
    }

    public function testARegisteredActionResolvesWithoutTheLegacyPath(): void
    {
        $map = $this->actionMap();

        // Any registered action will do -- this is about the RESOLUTION path,
        // not about which report happens to still have a controller. It named
        // reportDashboard until dashboard became a report definition, then
        // reportDomstreams until recordings moved to their own module.
        $this->assertArrayHasKey('base.visualizationFunnel', $map);
        $this->assertSame(
            'OWA\\Module\\Base\\Controller\\VisualizationFunnel',
            $map['base.visualizationFunnel']['class_name']
        );
    }
}
