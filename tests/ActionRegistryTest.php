<?php

use PHPUnit\Framework\TestCase;

/**
 * Every core controller must be registered against an action name.
 *
 * CoreAPI::performAction() has two branches. The registered one hands a class
 * name and path from the action map to Lib::simpleFactory(). The unregistered
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

        foreach (glob(OWA_BASE_DIR . '/modules/Base/Controller/*.php') as $file) {
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

            // Legacy owa_* names resolve through the compat bridge, not autoload.
            if (strncmp($class, 'owa_', 4) === 0) {
                $class = \OWA\Core\Lib::resolveNamespacedClass($class) ?? $class;
            }

            if (! class_exists($class)) {
                $broken[] = $action . ' -> ' . $meta['class_name'];
            }
        }

        $this->assertSame([], $broken,
            'Registered actions point at classes that do not exist: ' . implode(', ', $broken));
    }

    public function testARegisteredActionResolvesWithoutTheLegacyPath(): void
    {
        $map = $this->actionMap();

        $this->assertArrayHasKey('base.reportDashboard', $map);
        $this->assertSame(
            'OWA\\Module\\Base\\Controller\\ReportDashboard',
            $map['base.reportDashboard']['class_name']
        );
    }
}
