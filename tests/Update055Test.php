<?php

require_once __DIR__ . '/IngestionTestCase.php';

/**
 * event_type is wide enough for any custom event name, on raw and every cube,
 * and a long name is stored whole.
 */
final class Update055Test extends IngestionTestCase
{
    /** @var string */
    private $site;

    /** @var \OWA\Module\Base\Update\Update055 */
    private $update;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = md5('owa-test-site');
        $this->ensureSiteRegistered($this->site);

        $this->update = new \OWA\Module\Base\Update\Update055();

        /*
         * A cube of its own when the install has none. Run alone on a fresh
         * install there is no cube, and the cube half of the update would go
         * untested.
         */
        if ( ! \OWA\Module\Base\Classes\Cube\Cubes::allTables() ) {

            $this->assertTrue( (bool) \OWA\Module\Base\Classes\Cube\Cubes::create( self::FIXTURE_PROPERTY ),
                'creating a fixture cube' );

            $this->createdCube = true;
        }
    }

    const FIXTURE_PROPERTY = 7775000000000095;

    /** @var bool */
    private $createdCube = false;

    protected function tearDown(): void
    {
        // Whatever a case did, leave the tables at the width the code expects.
        $this->update->up();

        if ( $this->createdCube ) {

            foreach ( array( '', '_rebuild', '_computed' ) as $suffix ) {
                \OWA\Core\CoreAPI::dbSingleton()->query( sprintf( 'DROP TABLE IF EXISTS %s%s',
                    \OWA\Module\Base\Classes\Cube\Cubes::tableFor( self::FIXTURE_PROPERTY ), $suffix ) );
            }
        }

        parent::tearDown();
    }

    public function testUpWidensRawAndEveryCube(): void
    {
        $this->assertTrue($this->update->up());

        foreach ($this->tables() as $table) {
            $this->assertSame(64, $this->width($table), "$table.event_type");
            $this->assertTrue($this->nullable($table),
                "$table.event_type must stay as nullable as a fresh install makes it");
        }
    }

    /** Twice is the same as once. */
    public function testUpIsIdempotent(): void
    {
        $this->assertTrue($this->update->up());
        $this->assertTrue($this->update->up());

        $this->assertSame(64, $this->width($this->rawTable()));
    }

    /**
     * A 40-character custom event name -- the longest a site may use -- is
     * stored whole. It was cut to 24, so two names sharing their first 24
     * characters reported as one event.
     */
    public function testALongCustomEventNameIsStoredWhole(): void
    {
        $this->assertTrue($this->update->up());

        $name = 'checkout_step_completed_payment_method_x';
        $this->assertSame(40, strlen($name));
        $this->assertTrue(\OWA\Core\CoreAPI::isTrackingEventType($name), 'the fixture must be a legal name');

        $visitor = $this->uniqueGuid();
        $session = $this->uniqueSessionId();

        $this->fireEvent($name, [
            'site_id'       => $this->site,
            'visitor_id'    => $visitor,
            'session_id'    => $session,
            'page_url'      => 'https://owa-test-site/v2/long-name',
            'page_location' => 'https://owa-test-site/v2/long-name',
        ]);

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $rows = (array) $db->get_results(sprintf(
            "SELECT id, event_type FROM %s WHERE site_id = '%s' AND visitor_id = %d AND session_id = %d",
            $this->rawTable(), $this->site, (int) $visitor, (int) $session));

        foreach ($rows as $row) {
            $this->trackForCleanup('base.event_raw', (string) ((array) $row)['id'], 'id');
        }

        $this->assertSame([$name], array_column(array_map(fn($r) => (array) $r, $rows), 'event_type'));
    }

    /** The entity refuses to trim it: a trimmed name is a different event. */
    public function testTheColumnIsNotTruncatable(): void
    {
        $entity = \OWA\Core\CoreAPI::entityFactory('base.event_raw');
        $name = str_repeat('x', 70);

        $entity->set('event_type', $name);

        $this->assertSame($name, $entity->get('event_type'));
    }

    /** down() is the inverse: the old width, with a longer value cut to fit. */
    public function testDownRestoresTheOldWidth(): void
    {
        $this->assertTrue($this->update->down());

        foreach ($this->tables() as $table) {
            $this->assertSame(24, $this->width($table), "$table.event_type");
        }

        $this->assertTrue($this->update->up());
        $this->assertSame(64, $this->width($this->rawTable()));
    }

    /* ---------------- helpers ---------------- */

    private function rawTable(): string
    {
        return \OWA\Core\CoreAPI::entityFactory('base.event_raw')->getTableName();
    }

    /** @return string[] raw and every cube table that exists */
    private function tables(): array
    {
        $tables = array_merge([$this->rawTable()],
            \OWA\Module\Base\Classes\Cube\Cubes::allTables());

        $this->assertGreaterThan(1, count($tables), 'no cube table exists, so the cubes go untested');

        return $tables;
    }

    private function nullable(string $table): bool
    {
        $row = (array) \OWA\Core\CoreAPI::dbSingleton()->get_row(sprintf(
            "SELECT IS_NULLABLE AS n FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' AND COLUMN_NAME = 'event_type'",
            $table));

        return ($row['n'] ?? '') === 'YES';
    }

    private function width(string $table): int
    {
        $row = (array) \OWA\Core\CoreAPI::dbSingleton()->get_row(sprintf(
            "SELECT CHARACTER_MAXIMUM_LENGTH AS w FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '%s' AND COLUMN_NAME = 'event_type'",
            $table));

        return (int) ($row['w'] ?? 0);
    }
}
