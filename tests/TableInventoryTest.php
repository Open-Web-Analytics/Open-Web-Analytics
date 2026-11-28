<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\TableInventory;

require_once __DIR__ . '/bootstrap_owa.php';
require_once __DIR__ . '/fixtures/TestFixtureInventoryModule.php';

/**
 * Classes\TableInventory::owned(): the tables each module's ENTITIES own, read
 * from the module classes without building a module. Building a module runs
 * its constructor, which registers its handlers, jobs and actions into the
 * process.
 *
 * No database.
 */
final class TableInventoryTest extends TestCase
{
    /** Nothing is registered: the service and the event dispatch are as they were. */
    public function testBuildingTheInventoryRegistersNothing(): void
    {
        $before = $this->registrations();
        $built  = \OWA\Module\TestInventory\Module::$built;

        TableInventory::ownedBy(array_merge(TableInventory::moduleClasses(), [\OWA\Module\TestInventory\Module::class]));

        $this->assertSame($before, $this->registrations());
        $this->assertSame($built, \OWA\Module\TestInventory\Module::$built, 'the module was not built');
    }

    /** A module that is not active and was never built owns its entities' tables, each in its group. */
    public function testAModuleNeverBuiltOwnsItsTables(): void
    {
        $this->assertFalse(\OWA\Core\CoreAPI::serviceSingleton()->getModule('test_inventory'), 'not loaded');
        $this->assertNotContains('test_inventory', \OWA\Core\CoreAPI::getActiveModules(), 'not active');

        $owned = TableInventory::ownedBy([\OWA\Module\Base\Module::class, \OWA\Module\TestInventory\Module::class]);

        $this->assertSame(\OWA\Core\Entity::BACKUP_DATA, $owned['test_inventory_probe_log'] ?? null);
        $this->assertSame(\OWA\Core\Entity::BACKUP_APPLICATION, $owned['test_inventory_probe_setting'] ?? null);
        $this->assertSame(\OWA\Core\Entity::BACKUP_APPLICATION, $owned['site'] ?? null);
        $this->assertSame(0, \OWA\Module\TestInventory\Module::$built);
    }

    /** Every module directory, active or not: Domstream's tables are owned whether boot loaded it or not. */
    public function testEveryModuleDirectoryIsRead(): void
    {
        $this->assertSame([
            \OWA\Module\Base\Module::class,
            \OWA\Module\Domstream\Module::class,
            \OWA\Module\MaxmindGeoip\Module::class,
            \OWA\Module\Sqs\Module::class,
        ], TableInventory::moduleClasses());

        $owned = TableInventory::owned();

        $this->assertSame(\OWA\Core\Entity::BACKUP_DATA, $owned['domstream_chunk'] ?? null);
        $this->assertSame(\OWA\Core\Entity::BACKUP_DATA, $owned['domstream_payload'] ?? null);
        $this->assertSame(\OWA\Core\Entity::BACKUP_DATA, $owned['event_raw'] ?? null);
    }

    /**
     * What a module's construction would add to: each service map's keys, the
     * loaded modules and entities, and the event dispatch's listeners.
     *
     * @return array<string, mixed>
     */
    private function registrations(): array
    {
        $s = \OWA\Core\CoreAPI::serviceSingleton();
        $d = \OWA\Core\CoreAPI::getEventDispatch();

        return [
            'maps'      => array_map(static fn ($map) => array_keys((array) $map), $s->maps),
            'modules'   => array_keys($s->modules),
            'entities'  => $s->entities,
            'metrics'   => array_keys($s->metrics),
            'listeners' => array_keys($d->listeners),
            'events'    => array_map(static fn ($ids) => array_values((array) $ids), $d->listenersByEventType),
            'filters'   => array_keys($d->listenersByFilterType),
        ];
    }
}
