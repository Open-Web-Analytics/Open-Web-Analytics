<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * Every entity whose id is derived from its own content must be covered by the
 * id migration.
 *
 * WHY THIS EXISTS
 * The migration converts ids from 32-bit crc32 to 63-bit. Any entity it leaves
 * behind keeps a crc32 id while the code that looks it up starts deriving a
 * 63-bit one, so the lookup silently stops finding the row. base.site was
 * omitted exactly that way: owa_site.id is generateId( site_id ), nine call
 * sites load a site by it, and makeUrlCanonical is one of them -- so URLs
 * quietly stopped being canonicalised and every document id derived afterwards
 * differed from the ones derived before.
 *
 * The whole test suite passed with that bug present, because the failure only
 * exists ACROSS a scheme change. Inside one test run every id is derived by the
 * same function and is perfectly self-consistent; you need rows written under
 * crc32 and read under the wide scheme, which only happens on a migrated
 * installation. Every other test of the migration inspects its CONFIGURATION --
 * the dimension table, the gate order, the constants -- and none of them could
 * have noticed an entity simply missing from the list.
 *
 * So this does not read the list. It reads the DATA: for every entity, it tries
 * to reproduce each row's id by hashing that row's own columns. Anything it can
 * reproduce is content-derived by definition, whatever anyone believed when
 * writing the list, and must therefore be covered.
 */
final class ContentDerivedIdCoverageTest extends TestCase
{
    /** Rows sampled per entity. Enough to be sure, cheap enough to run. */
    private const SAMPLE = 25;

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; this test reads real rows.');
        }
    }

    /**
     * Entities the migration covers, including the composite one that has no
     * single content column.
     *
     * @return string[]
     */
    private function covered(): array
    {
        $cli = new ReflectionClass(\OWA\Module\Base\Controller\RederiveDimensionIdsCli::class);
        $m   = $cli->getMethod('dimensionNames');
        $m->setAccessible(true);

        return $m->invoke($cli->newInstanceWithoutConstructor());
    }

    /**
     * Entities whose id is reproducible from their own columns, discovered by
     * trying it rather than by being told.
     *
     * @return array  entity name => the column that reproduces it
     */
    private function discover(): array
    {
        $db      = \OWA\Core\CoreAPI::dbSingleton();
        $service = \OWA\Core\CoreAPI::serviceSingleton();
        $found   = [];

        foreach ($service->modules['base']->getEntities() as $name) {

            $entity = \OWA\Core\CoreAPI::entityFactory('base.' . $name);
            $rows   = $db->get_results(sprintf('SELECT * FROM %s LIMIT %d', $entity->getTableName(), self::SAMPLE));

            foreach ((array) $rows as $row) {

                $row = (array) $row;

                if (!isset($row['id'])) {
                    continue;
                }

                foreach ($row as $column => $value) {

                    if ($column === 'id' || !is_string($value) || $value === '') {
                        continue;
                    }

                    // Both forms ingestion uses: verbatim, and trimmed. Case is
                    // irrelevant because setStringGuid lowercases internally.
                    foreach ([$value, trim(strtolower($value))] as $candidate) {

                        if ((string) \OWA\Core\Lib::setStringGuid($candidate) === (string) $row['id']) {
                            $found['base.' . $name] = $column;
                            continue 3;
                        }
                    }
                }
            }
        }

        return $found;
    }

    public function testEveryContentDerivedIdIsCoveredByTheMigration(): void
    {
        $found = $this->discover();

        // Guard against passing for the wrong reason: on a database with no
        // usable rows this would find nothing and assert nothing.
        $this->assertGreaterThan(3, count($found),
            'too few content-derived ids discovered for this check to mean anything; '
            . 'the database may be empty, in which case this test proves nothing');

        $covered = $this->covered();
        $missing = [];

        foreach ($found as $entity => $column) {
            if (!in_array($entity, $covered, true)) {
                $missing[] = sprintf('%s (id = hash of %s)', $entity, $column);
            }
        }

        $this->assertSame([], $missing,
            "These entities derive their id from their own content but the migration does not "
            . "convert them. Their ids will stay 32-bit while lookups start deriving 63-bit ones, "
            . "and every lookup will silently stop finding the row:\n  " . implode("\n  ", $missing));
    }

    /**
     * The reverse is not asserted. An entity may be covered without being
     * discoverable here -- base.location_dim hashes country, state and city
     * concatenated, so no single column reproduces its id -- and that is correct
     * rather than a fault to report.
     */
    public function testTheCompositeDimensionIsCoveredWithoutBeingDiscoverable(): void
    {
        $this->assertContains('base.location_dim', $this->covered(),
            'location ids are derived from three columns at once and still need converting');

        $this->assertArrayNotHasKey('base.location_dim', $this->discover(),
            'no single column should reproduce a composite id; if one does, the derivation changed');
    }
}
