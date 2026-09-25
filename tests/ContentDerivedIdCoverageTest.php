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

    /** Distinctive enough that the cleanup cannot reach a real row. */
    private const SEED = 'owa-content-derived-id-coverage-';

    /**
     * Rows this test SEEDS so that discovery has something to discover.
     *
     * WHY SEEDING AND NOT READING WHATEVER IS THERE. Discovery works by trying
     * to reproduce a row's id from its own columns, so it needs rows. On a
     * developer's database there are thousands and it finds twelve entities;
     * on a scratch install there is almost nothing and it found ONE, which
     * tripped the guard below and made this the last file failing the
     * isolation sweep. It passed in the full suite only because tests that ran
     * earlier had left rows behind -- which is the definition of the thing
     * that sweep looks for.
     *
     * Seeding does not weaken the method. These rows are written through the
     * ordinary entity path with ids derived the ordinary way, so discovery
     * still has to REPRODUCE them by hashing, and an entity that stopped being
     * content-derived would stop being found here exactly as before. What the
     * seed removes is the dependence on ambient data, not the discovery.
     *
     * @return array<string, array<string, string>> entity => columns to set
     */
    private static function seeds(): array
    {
        return [
            'base.os' => [
                'name' => self::SEED . 'os',
            ],
            'base.ua' => [
                'ua'           => self::SEED . 'ua',
                'browser_type' => 'browser',
                'browser'      => 'Seeded',
            ],
            'base.document' => [
                'url'        => 'http://example.test/' . self::SEED . 'document',
                'uri'        => '/' . self::SEED . 'document',
                'page_title' => 'Seeded document',
                'page_type'  => 'page',
            ],
            'base.search_term_dim' => [
                'terms'      => self::SEED . 'terms',
                'term_count' => 1,
            ],
        ];
    }

    /** The column whose value the id is derived from, per seeded entity. */
    private static function seedContentColumn(string $entity): string
    {
        return [
            'base.os'               => 'name',
            'base.ua'               => 'ua',
            'base.document'         => 'url',
            'base.search_term_dim'  => 'terms',
        ][$entity];
    }

    public static function setUpBeforeClass(): void
    {
        if (!owa_test_db_available()) {
            return;
        }

        self::dropSeeds();

        foreach (self::seeds() as $name => $columns) {

            $entity = \OWA\Core\CoreAPI::entityFactory($name);

            /*
             * The id is derived the way ingestion derives it, because a hand
             * -picked one would make discovery's reproduction trivially true
             * and prove nothing about how the entity actually behaves.
             */
            $columns['id'] = \OWA\Core\Lib::setStringGuid(
                $columns[self::seedContentColumn($name)]);

            $entity->setProperties($columns);

            if (!$entity->create()) {
                throw new \RuntimeException(sprintf('seeding %s failed: %s',
                    $name, \OWA\Core\CoreAPI::dbSingleton()->lastQueryError()));
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (owa_test_db_available()) {
            self::dropSeeds();
        }
    }

    private static function dropSeeds(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach (self::seeds() as $name => $columns) {

            $entity = \OWA\Core\CoreAPI::entityFactory($name);
            $column = self::seedContentColumn($name);

            // By the derived id, not by a LIKE on the content: the id is what
            // uniquely identifies the row this test wrote.
            $db->query(sprintf('DELETE FROM %s WHERE id = %s',
                $entity->getTableName(),
                $db->prepare((string) \OWA\Core\Lib::setStringGuid($columns[$column]))));
        }
    }

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

        /*
         * Guard against passing for the wrong reason: with no usable rows this
         * would find nothing and assert nothing. The seeded entities put a
         * floor under it on any database, so reaching here means discovery ran
         * rather than that the installation happened to be busy.
         */
        $this->assertGreaterThanOrEqual(count(self::seeds()), count($found),
            'discovery found fewer entities than this test seeded, so it is not '
            . 'reproducing ids it should be able to reproduce');

        foreach (array_keys(self::seeds()) as $seeded) {

            $this->assertArrayHasKey($seeded, $found,
                $seeded . ' was seeded with a content-derived id and discovery did not find it');
        }

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
