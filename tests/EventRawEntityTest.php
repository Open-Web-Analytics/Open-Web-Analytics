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
        return \OWA\Core\CoreAPI::entityFactory('base.event_raw');
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

        /*
         * 66. It was 62: element_path went and is_outbound arrived (Update053, net
         * zero), then file_name and file_extension were promoted out of `params`
         * (Update054) and search_term after them, which needed no migration at all --
         * Update034 builds this table from the entity and v2 has never shipped.
         * screen_resolution (Update058) is a VARCHAR(16) the tracker sends on
         * every event. 67 with created_at (Update063), when a row reached raw --
         * raw only: the cube entity drops it, so it costs the custom-dimension
         * ceiling nothing. 73 with the six prior_touch_* columns (Update066), raw
         * only for the same reason: the cube's attributed_* is their reading.
         *
         * A COUNT IS THE POINT HERE, not an inconvenience. The other nine
         * param-bound first-class properties were deliberately left in the bag --
         * most installs will never group by an element class or a form name, and a
         * column is width on every row of every Property. Promoting nine of them
         * was measured at taking the custom-dimension ceiling from 62 to 37 on
         * MySQL 8.4. This number moving is how that decision gets noticed being
         * reversed one column at a time.
         */
        $this->assertCount(73, $columns);

        // browser_type, and NOT `browser`. Both columns existed and both were
        // written from the one property -- config/dimensions.php declares
        // browserType against browser_type, and nothing read the other. Update052
        // dropped the VARCHAR(128) copy: on a table whose row cannot exceed
        // 65,535 bytes, a duplicate is budget a real dimension does not get.
        $this->assertContains('browser_type', $columns);
        $this->assertNotContains('browser', $columns,
            'browser was a copy of browser_type that no dimension read');

        // Spot the ones that carry a decision rather than listing all 54.
        foreach ([
            'id', 'event_type', 'site_id', 'visitor_id', 'session_id', 'ts',
            'yyyymmdd', 'page_location', 'page_path', 'page_query',
            'tagged_source', 'tagged_medium', 'tagged_campaign',
            'engagement_msec', 'scroll_depth', 'is_outbound', 'consent_state',
            // Promoted out of params; the element and form params were not.
            'file_name', 'file_extension', 'search_term',
            'user_id', 'content_group', 'currency', 'session_start_ts',
            'device_type', 'device_brand', 'device_model', 'raw_ua', 'params',
            'referer_host', 'referer_query', 'prior_session_start_ts',
            // Device order, so a late beacon does not sort after events that
            // happened after it.
            'event_seq',
            // Which tracker generation wrote the row -- the evidence a compat
            // bridge can ever be deleted on.
            'beacon_version',
            /*
             * The purchase, past its total: tax and shipping are SUMMED metrics
             * and a metric needs a column to sum, and transaction_id is what
             * makes a purchase countable once. The gateway and the order source
             * are params, because nothing adds them up.
             */
            'transaction_id', 'tax', 'shipping',
            /*
             * The VISITOR's network host, reverse DNS of their address. Three
             * hosts reach an event -- the page's (host), this server's
             * (HTTP_HOST) and the visitor's -- and v1 reported the third as its
             * `host` dimension, a name v2 gave to the first.
             */
            'remote_host',
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
            /*
             * The tracker's own clock in seconds. Sent on every beacon until it
             * became device-local: `ts` is the edge receipt in microseconds and
             * client_ts_usec is this same client clock at higher resolution, so
             * the wire was carrying a third spelling of one instant. Never a
             * column here, and now not on the wire either.
             */
            'timestamp',
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
             'site_type_date' => ['site_id', 'event_type', 'yyyymmdd'],
             'site_transaction' => ['site_id', 'transaction_id'],
             // The realtime window, raw only (Update064).
             'site_ts' => ['site_id', 'ts']],
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
                  'currency', 'params', 'session_start_ts', 'language',
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

    public function testTheVisitorStoreIsUniqueOnTheVisitorAndNotPartitioned(): void
    {
        $entity = \OWA\Core\CoreAPI::entityFactory('base.visitor_acquisition');

        $this->assertSame(['visitor_id_unique' => ['visitor_id']], $entity->getUniqueIndexes(),
            'Insert-if-absent depends on uniqueness on visitor_id ALONE.');

        // Visitor ids are random, so they are not the clustered key: rows
        // append on an ascending id instead (Update060).
        $this->assertSame('id', $entity->getPrimaryKeyColumn());

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
     * The collection gate is GONE, not merely defaulted off.
     *
     * `v2_raw_collection` was development scaffolding for exercising ingest
     * against one site, and while it existed it had to be denylisted from the
     * options form -- that form persists whatever it is posted minus a denylist
     * that fails open, and an install-wide value is what every Profile inherits.
     * Now that the tracker sends v2-shaped events there is nothing to gate on,
     * so the setting, its default and its denylist entry all go. Asserted as an
     * absence because a leftover default would be read by nothing and a
     * leftover denylist entry would protect a setting that no longer exists.
     */
    public function testTheCollectionGateIsGoneEntirely(): void
    {
        // Read from the DECLARATION, not through getSetting(): that answers
        // false for an absent key and for one declared false, so it cannot
        // tell "removed" from "defaulted off" -- which is the whole claim.
        $class = new ReflectionClass(\OWA\Module\Base\Classes\Settings::class);
        $method = $class->getMethod('getDefaultSettingsArray');
        $method->setAccessible(true);

        $declared = $method->invoke($class->newInstanceWithoutConstructor());

        $this->assertArrayNotHasKey('v2_raw_collection', $declared['base'],
            'the setting must not still be declared with a default');

        $denylist = array_merge(
            \OWA\Module\Base\Classes\Settings::staticSettings()['base'],
            \OWA\Module\Base\Classes\Settings::databaseStateSettings()['base']);

        $this->assertArrayNotHasKey('v2_raw_collection', $denylist,
            'nothing left to protect from the options form');

        foreach (glob(OWA_BASE_DIR . '/modules/Base/templates/*.php') as $template) {

            $this->assertStringNotContainsString('v2_raw_collection', file_get_contents($template),
                basename($template) . ' still names the removed flag.');
        }
    }

}
