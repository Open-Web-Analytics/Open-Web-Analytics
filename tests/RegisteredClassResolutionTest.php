<?php

use PHPUnit\Framework\TestCase;

/**
 * "Resolve every registered dotted-name" test — net (b) of the
 * namespace-migration safety net (Phase 6, stage 0).
 *
 * WHY THIS EXISTS
 * ---------------
 * OWA does not reference most of its classes by their PHP class name. It
 * references them by a REGISTERED DOTTED STRING id — 'base.request',
 * 'base.configurableMetric', etc. — that the resolution seam
 * (owa_coreAPI::moduleSpecificFactory / moduleRequireOnce) turns into a file
 * path AND a synthesized class name ('owa_' . <file>) at runtime. Those dotted
 * ids live in registration data (service->entities, service->metrics, ...) and
 * are a public contract we deliberately do NOT rename during the migration.
 *
 * The ClassLoadSmokeTest (net a) proves every class FILE loads and declares its
 * class. This test proves the OTHER half: that every dotted id the framework
 * has registered still drives cleanly THROUGH the seam to a loadable class.
 * That is exactly the machinery the namespace migration rewires (prefix
 * synthesis in owa_lib + the $class_ns factory defaults), so if a rename breaks
 * the dotted-id -> class-name mapping, this test goes red even though net (a)
 * — which reads its expectation from the file itself — would stay green.
 *
 * It does NOT hit the database (entity construction and class loading are
 * metadata-only), so it runs in a no-DB environment.
 */
final class RegisteredClassResolutionTest extends TestCase
{
    private static object $service;

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/bootstrap_owa.php';
        $service = owa_coreAPI::serviceSingleton();
        // Force every active module to run its register*() callbacks so the
        // registries are fully populated the way a real request would see them.
        $service->initializeFramework();
        self::$service = $service;
    }

    /**
     * Every registered entity dotted-id resolves through entityFactory to an
     * owa_entity instance. The registry VALUE is the dotted id (the key is a
     * short alias).
     */
    public function testEveryRegisteredEntityResolves(): void
    {
        // instanceof does NOT autoload. A legacy alias that nothing has touched
        // yet is simply undefined, and `$obj instanceof owa_entity` then reads
        // false for EVERY entity -- so this case failed when run alone and
        // passed in the suite only because some earlier test had loaded the
        // alias first. Assert the precondition here, where it also loads it,
        // rather than inheriting it from whatever ran before.
        $this->assertTrue(class_exists('owa_entity'),
            'owa_entity must resolve through the compat bridge before instanceof can see it.');

        $entities = self::$service->entities;
        $this->assertGreaterThan(
            20,
            count($entities),
            'Expected the full base entity set to be registered.'
        );

        $failures = [];
        foreach ($entities as $short => $dotted) {
            try {
                $obj = owa_coreAPI::entityFactory($dotted);
                if (!$obj instanceof owa_entity) {
                    $failures[] = "$dotted ($short) => "
                        . (is_object($obj) ? get_class($obj) : gettype($obj));
                }
            } catch (\Throwable $e) {
                $failures[] = "$dotted ($short) THREW " . $e->getMessage();
            }
        }

        $this->assertSame(
            [],
            $failures,
            "Registered entity dotted-id(s) did not resolve through the "
            . "factory seam:\n" . implode("\n", $failures)
        );
    }

    /**
     * Every distinct metric CLASS referenced by the metric registry loads
     * through the require_once seam and its synthesized class name resolves.
     *
     * Metrics are registered as definition arrays whose 'class' key is the
     * dotted id of the class that implements them (most point at the single
     * parametric 'base.configurableMetric'; the rest are bespoke metric
     * classes). We assert the CLASS loads rather than instantiating, because
     * the parametric metric cannot be constructed without its per-metric params
     * — but its class-name synthesis + require is precisely the seam behavior
     * the migration must preserve.
     */
    public function testEveryRegisteredMetricClassLoads(): void
    {
        $classes = [];
        foreach (self::$service->metrics as $defs) {
            foreach ((array) $defs as $def) {
                if (isset($def['class'])) {
                    $classes[$def['class']] = true;
                }
            }
        }
        $classes = array_keys($classes);

        /*
         * Counts registered metric NAMES, not distinct implementation classes.
         *
         * It asserted more than 10 distinct classes, as a proxy for "the
         * registry loaded". That proxy died with the conversion to configuration:
         * nearly every metric now resolves to base.configurableMetric, so the
         * distinct-class count is 5 and falling -- by design, not by breakage.
         * The thing actually worth guarding is that the catalog is populated and
         * every class it names can be loaded, which the loop below still does.
         */
        $this->assertGreaterThan(
            50,
            count( self::$service->metrics ),
            'Expected the metric catalog to be populated.'
        );

        $this->assertNotEmpty(
            $classes,
            'Expected at least one metric implementation class to resolve.'
        );

        $failures = [];
        foreach ($classes as $dotted) {
            [$module, $file] = explode('.', $dotted);
            $class = 'owa_' . $file;

            if (!class_exists($class, false)) {
                owa_coreAPI::moduleRequireOnce($module, 'metrics', $file);
            }
            if (!class_exists($class, false)) {
                $failures[] = "$dotted => class '$class' did not load via the seam";
            }
        }

        $this->assertSame(
            [],
            $failures,
            "Registered metric class(es) did not resolve through the require "
            . "seam:\n" . implode("\n", $failures)
        );
    }
}
