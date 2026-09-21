<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * The shape of v2's two tables.
 *
 * An entity's declarations are what CREATE TABLE emits, so these assertions are
 * about the SCHEMA, not about a row. They are here because the decisions they
 * pin are easy to undo by accident and expensive to undo in production: a
 * column silently becoming NOT NULL changes what absence looks like, and an
 * entity accidentally extending FactTable would add ten dimension foreign keys
 * to a table that exists to have none.
 */
final class EventRawEntityTest extends TestCase
{
    private function raw()
    {
        return owa_coreAPI::entityFactory('base.event_raw');
    }

    public function testItIsNotAFactTable(): void
    {
        $this->assertNotInstanceOf(\OWA\Core\Entity\FactTable::class, $this->raw(),
            'FactTable\'s constructor is the star schema -- ten dimension foreign keys, '
          . 'eight date parts -- and inheriting it would declare every one of them.');

        $this->assertInstanceOf(\OWA\Core\Entity::class, $this->raw());
    }

    public function testItCarriesNoForeignKeys(): void
    {
        // null when none were declared -- getAllForeignKeys() reads a table
        // property that setProperty() only creates for a foreign-key column.
        $this->assertEmpty($this->raw()->getAllForeignKeys(),
            'No joins in the reporting path is the whole point of the single table.');
    }

    public function testTheColumnSet(): void
    {
        $columns = $this->raw()->getColumns();

        $this->assertCount(57, $columns);

        // Spot the ones that carry a decision rather than listing all 54.
        foreach ([
            'id', 'event_type', 'site_id', 'visitor_id', 'session_id', 'ts',
            'yyyymmdd', 'page_location', 'page_path', 'page_query',
            'tagged_source', 'tagged_medium', 'tagged_campaign',
            'engagement_msec', 'scroll_depth', 'element_path', 'consent_state',
            'user_id', 'content_group', 'currency', 'clock_offset_usec',
            'device_type', 'device_brand', 'device_model', 'raw_ua', 'params',
            'referer_host', 'referer_query', 'prev_event_ts',
        ] as $name) {
            $this->assertContains($name, $columns, "owa_event_raw must declare $name");
        }

        // Deliberately absent, each per a settled decision.
        foreach ([
            'is_new_visitor', 'is_entry_page', 'is_exit_page', 'year', 'month',
            'day', 'dayofweek', 'dayofyear', 'weekofyear', 'hour', 'minute',
            'document_id', 'referer_id', 'ua_id', 'host_id', 'os_id',
            'location_id', 'source_id', 'campaign_id', 'ad_id', 'is_robot',
            'landing_url', 'source', 'medium', 'campaign',
        ] as $name) {
            $this->assertNotContains($name, $columns,
                "$name is deliberately not a raw column -- it is a derivation, a dimension "
              . 'key, a date part, or the pass\'s to write.');
        }
    }

    public function testThePrimaryKeyIsCompositeWithThePartition(): void
    {
        $entity = $this->raw();

        $this->assertSame('id', $entity->getPrimaryKeyColumn());
        $this->assertSame('yyyymmdd', $entity->getPartitionColumn(),
            'Partitioning is what makes retention a metadata drop and prunes every query.');
    }

    public function testTheMeasuredIndexes(): void
    {
        $entity = $this->raw();

        $this->assertSame(
            ['site_date' => ['site_id', 'yyyymmdd'],
             'site_type_date' => ['site_id', 'event_type', 'yyyymmdd']],
            $entity->getCompositeIndexes());

        $this->assertTrue($entity->isColumnIndexed('visitor_id'));
        $this->assertTrue($entity->isColumnIndexed('session_id'));
    }

    /**
     * Identity and time are NOT nullable; everything else is.
     *
     * The id is derived from exactly these five, so a row missing one would
     * collide every such event onto a single id. Everything else can honestly
     * be absent, and absence is NULL.
     */
    public function testWhatMayBeAbsent(): void
    {
        $entity = $this->raw();

        foreach (['id', 'event_type', 'site_id', 'visitor_id', 'session_id',
                  'ts', 'yyyymmdd', 'is_goal_event'] as $name) {
            $this->assertEmpty($entity->getColumn($name)->nullable,
                "$name must not be nullable: it identifies the row.");
        }

        foreach (['user_id', 'page_query', 'content_group', 'tagged_source',
                  'city', 'region', 'country_code', 'ip_address', 'consent_state',
                  'click_x', 'engagement_msec', 'scroll_depth', 'revenue',
                  'currency', 'params', 'clock_offset_usec', 'language',
                  'host', 'page_title'] as $name) {
            $this->assertNotEmpty($entity->getColumn($name)->nullable,
                "$name must be nullable: absence is stored as NULL in v2.");
        }
    }

    /**
     * A boolean that can be absent holds THREE values and each one groups
     * separately -- which cost 1.x a pie chart drawing two slices with the same
     * label. NOT NULL alone is not enough: with no default an omitted column is
     * refused under a strict sql_mode and written as an implicit 0 under a
     * permissive one, so the guarantee would depend on a server setting.
     */
    public function testIsGoalEventCannotHoldAThirdValue(): void
    {
        $definition = $this->raw()->getColumnDefinition('is_goal_event', true);

        $this->assertStringContainsString('NOT NULL', $definition);
        $this->assertStringContainsString('DEFAULT 0', $definition);
    }

    /**
     * Signed, against the design note, because OWA runs with a permissive
     * sql_mode: a negative value written to an UNSIGNED column is clamped to 0
     * rather than refused, and v1 has negative visitor ids to migrate.
     */
    public function testIdColumnsAreSigned(): void
    {
        $entity = $this->raw();

        foreach (['id', 'visitor_id', 'session_id', 'ts'] as $name) {
            $this->assertStringNotContainsStringIgnoringCase(
                'UNSIGNED', (string) $entity->getColumn($name)->get('data_type'),
                "$name must be signed: an UNSIGNED column silently clamps a negative id to 0.");
        }
    }

    public function testTheVisitorStoreIsKeyedOnTheVisitorAndNotPartitioned(): void
    {
        $entity = owa_coreAPI::entityFactory('base.visitor_acquisition');

        $this->assertSame('visitor_id', $entity->getPrimaryKeyColumn(),
            'Insert-if-absent depends on uniqueness on visitor_id ALONE.');

        $this->assertNull($entity->getPartitionColumn(),
            'Partitioning would force last_seen into the key and take that uniqueness away.');

        $this->assertTrue($entity->isColumnIndexed('last_seen'),
            'The TTL sweep is a DELETE ... WHERE last_seen < cutoff.');

        foreach (['acq_source', 'acq_medium', 'acq_campaign', 'acq_ad',
                  'acq_search_terms', 'acq_referer_url', 'acq_ts', 'last_seen'] as $name) {
            $this->assertContains($name, $entity->getColumns());
        }
    }

    /**
     * The parallel pipeline is development scaffolding and must not be
     * reachable as a product feature.
     *
     * v2 does not ship alongside v1: it is built, v1's history is migrated into
     * it, collection switches over, and v1's tables are dropped as a separate
     * decision. The setting exists only so the v2 work can exercise ingest
     * against one real site before the switch, rather than making the switch
     * itself the first time it ever runs.
     *
     * No template renders a field for it, but that is not the guarantee -- the
     * options form persists whatever it is posted minus a denylist, and an
     * install-wide value is what every Profile inherits.
     */
    public function testTheDevelopmentPipelineIsOffAndNotWebSettable(): void
    {
        $this->assertFalse(
            (bool) owa_coreAPI::getSetting('base', 'v2_raw_collection'),
            'Every site is off by default, and the default is the important half.');

        $denylist = array_merge(
            \OWA\Module\Base\Classes\Settings::configFileOnlySettings()['base'],
            \OWA\Module\Base\Classes\Settings::databaseStateSettings()['base']);

        $this->assertArrayHasKey('v2_raw_collection', $denylist,
            'The options form must not be able to turn the second pipeline on install-wide.');

        foreach (glob(OWA_BASE_DIR . '/modules/Base/templates/*.php') as $template) {

            $this->assertStringNotContainsString('v2_raw_collection', file_get_contents($template),
                basename($template) . ' renders the development flag as a setting.');
        }
    }

    /**
     * The partition commands select on the entity declaring a partition column,
     * not on the base class -- which is what lets owa_event_raw join the
     * rotation without extending FactTable.
     */
    public function testEveryFactTableStillDeclaresAPartitionColumn(): void
    {
        $service = owa_coreAPI::serviceSingleton();

        $found = 0;

        foreach ($service->modules['base']->getEntities() as $name) {

            $entity = owa_coreAPI::entityFactory('base.' . $name);

            if (!$entity instanceof \OWA\Core\Entity\FactTable) {
                continue;
            }

            $found++;
            $this->assertSame('yyyymmdd', $entity->getPartitionColumn(),
                "base.$name is a FactTable but declares no partition column, so the "
              . 'partition commands would silently stop covering it.');
        }

        $this->assertGreaterThan(5, $found);
    }
}
