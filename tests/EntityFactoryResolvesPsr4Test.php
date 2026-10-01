<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * An entity resolves by PSR-4 convention: base.custom_report is
 * OWA\Module\Base\Entity\CustomReport. There is no other route since v2.0.
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

        $this->assertGreaterThan(15, $checked, 'the entity list looks empty; the sweep proved nothing');
    }

    /** Every registered entity's name follows the convention. */
    public function testEveryEntityNameFollowsTheConvention(): void
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
            'these entities do not follow the convention, so nothing can construct them: '
            . implode(', ', $stragglers));
    }

    /** The name the caller asked for still rides on the object. */
    public function testTheEntityCarriesTheNameItWasAskedFor(): void
    {
        $entity = \OWA\Core\CoreAPI::entityFactory('base.custom_report');

        $this->assertSame('base.custom_report', $entity->name);
    }

    /** A name that follows no class is refused, naming the convention. */
    public function testAnEntityWithNoClassIsRefused(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/pre-PSR-4/');

        \OWA\Core\CoreAPI::entityFactory('base.no_such_entity');
    }

    /** snake_case becomes PascalCase, which is where the class actually is. */
    public function testAMultiWordEntityNameResolves(): void
    {
        $this->assertInstanceOf(
            \OWA\Module\Base\Entity\GoalEventCondition::class,
            \OWA\Core\CoreAPI::entityFactory('base.goal_event_condition'));
    }

    /**
     * The CONVENTION itself, reached directly rather than through the factory.
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
