<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * An entity resolves by PSR-4 convention, not through the compatibility bridge.
 *
 * entityFactory() used to turn 'base.custom_report' into the legacy class name
 * owa_custom_report and resolve THAT through owa_compat_class_map(). So OWA's
 * own entity resolution depended on a file whose stated purpose is third-party
 * callers of the old names, and which is removed at 2.0 -- and every entity
 * added since the PSR-4 migration had to be registered there or fail with
 *
 *     Class File modules/entities/Base/owa_<name>.php not existend!
 *
 * naming a directory layout that has not existed since the migration.
 */
final class EntityFactoryResolvesPsr4Test extends TestCase
{
    /** Every registered entity resolves, and to its namespaced class. */
    public function testEveryEntityResolvesToItsNamespacedClass(): void
    {
        $service = \OWA\Core\CoreAPI::serviceSingleton();
        $checked = 0;

        foreach ($service->modules['base']->getEntities() as $name) {

            $entity = \OWA\Core\CoreAPI::entityFactory('base.' . $name);

            $this->assertStringStartsWith(
                'OWA\\Module\\Base\\Entity\\',
                get_class($entity),
                sprintf('base.%s resolved to %s, which is not the PSR-4 class',
                    $name, get_class($entity)));

            $checked++;
        }

        $this->assertGreaterThan(20, $checked, 'the entity list looks empty; the sweep proved nothing');
    }

    /**
     * THE POINT OF THE CHANGE: the bridge is no longer load-bearing.
     *
     * Every registered entity resolves by convention alone, so removing the
     * entity entries from owa_compat_class_map() would break nothing in OWA --
     * which is what has to be true before that file can go at 2.0.
     *
     * The entries stay regardless, because third-party code calling
     * `new owa_custom_report()` is exactly what the bridge is for. What this
     * asserts is that OWA itself no longer needs them.
     */
    public function testNoEntityDependsOnTheCompatBridge(): void
    {
        $method = new ReflectionMethod(\OWA\Core\CoreAPI::class, 'namespacedEntityClass');
        $method->setAccessible(true);

        $service  = \OWA\Core\CoreAPI::serviceSingleton();
        $stragglers = array();

        foreach ($service->modules['base']->getEntities() as $name) {

            if ($method->invoke(null, 'base.' . $name) === null) {

                $stragglers[] = $name;
            }
        }

        $this->assertSame(array(), $stragglers,
            'these entities still resolve only through the compatibility bridge, so removing '
            . 'it at 2.0 would break them: ' . implode(', ', $stragglers));
    }

    /** The name the caller asked for still rides on the object. */
    public function testTheEntityCarriesTheNameItWasAskedFor(): void
    {
        $entity = \OWA\Core\CoreAPI::entityFactory('base.custom_report');

        $this->assertSame('base.custom_report', $entity->name);
    }

    /**
     * THE PROOF: these entities have NO entry in the bridge, and resolve anyway.
     *
     * Eleven entity entries were deleted along with this change. They were
     * never legacy names -- nothing outside OWA ever called owa_custom_report,
     * which postdates the namespace migration entirely. They existed only
     * because entityFactory() synthesized that name and looked it up, which is
     * what the map's own comment said about them.
     *
     * Asserted both ways round, because either half alone is weak: that the
     * entry is really gone, and that the entity still resolves.
     *
     * @dataProvider unmappedEntities
     */
    public function testAnEntityWithNoBridgeEntryStillResolves(string $legacy, string $registered, string $expected): void
    {
        $this->assertArrayNotHasKey($legacy, \owa_compat_class_map(),
            sprintf('%s is back in the compat map; this test is about resolving without it', $legacy));

        $this->assertSame($expected, get_class(\OWA\Core\CoreAPI::entityFactory($registered)));
    }

    public static function unmappedEntities(): array
    {
        return array(
            array('owa_custom_report', 'base.custom_report', 'OWA\\Module\\Base\\Entity\\CustomReport'),
            array('owa_goal_event', 'base.goal_event', 'OWA\\Module\\Base\\Entity\\GoalEvent'),
            array('owa_organization', 'base.organization', 'OWA\\Module\\Base\\Entity\\Organization'),
            array('owa_notification_state', 'base.notification_state', 'OWA\\Module\\Base\\Entity\\NotificationState'),
            array('owa_setting', 'base.setting', 'OWA\\Module\\Base\\Entity\\Setting'),
        );
    }

    /** snake_case becomes PascalCase, which is where the class actually is. */
    public function testAMultiWordEntityNameResolves(): void
    {
        $this->assertInstanceOf(
            \OWA\Module\Base\Entity\GoalEventCondition::class,
            \OWA\Core\CoreAPI::entityFactory('base.goal_event_condition'));
    }

    /**
     * The CONVENTION itself, not just its outcome.
     *
     * The tests above assert which class comes back, and the compat map
     * produces the same answer -- so with the map intact they pass whether or
     * not the new path exists. This reaches the resolver directly, so it fails
     * if the convention stops working even while the bridge is there to cover
     * for it.
     *
     * @dataProvider entityNames
     */
    public function testTheConventionResolvesAName(string $registered, ?string $expected): void
    {
        $method = new ReflectionMethod(\OWA\Core\CoreAPI::class, 'namespacedEntityClass');
        $method->setAccessible(true);

        $this->assertSame($expected, $method->invoke(null, $registered));
    }

    public static function entityNames(): array
    {
        return array(
            'single word'      => array('base.user', 'OWA\\Module\\Base\\Entity\\User'),
            'two words'        => array('base.custom_report', 'OWA\\Module\\Base\\Entity\\CustomReport'),
            'three words'      => array('base.goal_event_condition', 'OWA\\Module\\Base\\Entity\\GoalEventCondition'),
            // Resolves only when the class is really there, so an unknown name
            // falls back instead of naming a class nobody wrote.
            'unknown entity'   => array('base.no_such_entity', null),
            'unknown module'   => array('nosuchmodule.user', null),
            // Not a module.entity name at all.
            'no dot'           => array('user', null),
            'empty entity'     => array('base.', null),
        );
    }

    /** A name nothing defines still falls through rather than resolving. */
    public function testAnUnknownEntityDoesNotResolveByConvention(): void
    {
        $this->expectException(\Exception::class);

        \OWA\Core\CoreAPI::entityFactory('base.no_such_entity_exists_here');
    }
}
