<?php

use PHPUnit\Framework\TestCase;

/**
 * No 1.x `owa_*` class name resolves on v2.
 *
 * tests/fixtures/legacy_class_names.json is every global-namespace class,
 * interface and trait name the tree declared before the PSR-4 migration -- 406
 * of them, frozen then. 1.x kept them resolving through a compatibility bridge
 * of class_alias()es; v2.0 removed the bridge (UPGRADING.md), so each one must
 * now be unknown. A name that still resolves means an alias, or a class
 * declared under its old name, came back.
 *
 * Queue payloads serialized under `owa_event` are read without the name
 * existing (EventQueue::currentClassName()), so it is in the list like the rest.
 */
final class LegacyClassNameContractTest extends TestCase
{
    private const FROZEN_COUNT = 406;

    public static function setUpBeforeClass(): void
    {
        // A full boot, so anything that would declare or alias an old name has run.
        require_once __DIR__ . '/bootstrap_owa.php';
    }

    /** @return string[] */
    private function legacyNames(): array
    {
        $names = json_decode((string) file_get_contents(__DIR__ . '/fixtures/legacy_class_names.json'), true);

        $this->assertIsArray($names, 'the legacy class-name fixture is not valid JSON');

        return $names;
    }

    public function testTheListIsTheWholeFrozenSet(): void
    {
        $this->assertCount(self::FROZEN_COUNT, $this->legacyNames(), 'the fixture lost names');
    }

    public function testNoLegacyClassNameResolves(): void
    {
        $resolves = [];

        foreach ($this->legacyNames() as $name) {
            // With autoload: an autoloader that aliased the name would be caught too.
            if (class_exists($name) || interface_exists($name) || trait_exists($name)) {
                $resolves[] = $name;
            }
        }

        $this->assertSame([], $resolves, "these 1.x class names still resolve:\n" . implode("\n", $resolves));
    }

    /** The names a module built on most are gone in their PSR-4 form's favour. */
    public function testTheBaseClassesResolveOnlyByTheirNamespacedNames(): void
    {
        foreach ([
            'owa_coreAPI'    => \OWA\Core\CoreAPI::class,
            'owa_module'     => \OWA\Core\Module::class,
            'owa_entity'     => \OWA\Core\Entity::class,
            'owa_controller' => \OWA\Core\Controller::class,
            'owa_event'      => \OWA\Module\Base\Classes\Event::class,
        ] as $legacy => $current) {
            $this->assertTrue(class_exists($current), $current);
            $this->assertFalse(class_exists($legacy), $legacy);
        }
    }
}
