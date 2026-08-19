<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * A dimension row found by a content-derived id must actually hold that content.
 *
 * WHY THIS EXISTS
 * Every dimension handler shares one shape: derive an id from the content, load
 * the row at that id, and reuse it if one comes back. Reuse silently assumes the
 * row IS the content the id came from. That holds until two different values
 * derive the same id, at which point the fact row's foreign key points at
 * somebody else's dimension and the two are merged in every report that touches
 * them -- permanently, and with nothing written down anywhere.
 *
 * Widening the hash to 63 bits makes this rare rather than impossible: about a
 * 0.0005% chance across ten million dimension rows. Rare and silent is the worst
 * combination to debug, so the reuse path compares and reports.
 */
final class IdCollisionDetectionTest extends TestCase
{
    /** @var string[] */
    private $created = [];

    protected function setUp(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; skipping id collision test.');
        }
    }

    protected function tearDown(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ($this->created as $id) {
            $db->query(sprintf("DELETE FROM owa_ua WHERE id = '%s'", $db->prepare($id)));
        }

        $this->created = [];
    }

    /** Persist a ua dimension row holding $content at $id, and return it loaded. */
    private function seed(string $id, string $content): object
    {
        $ua = \OWA\Core\CoreAPI::entityFactory('base.ua');
        $ua->set('id', $id);
        $ua->set('ua', $content);
        $ua->create();

        $this->created[] = $id;

        $loaded = \OWA\Core\CoreAPI::entityFactory('base.ua');
        $loaded->load($id);

        return $loaded;
    }

    public function testARowHoldingDifferentContentIsReportedAsACollision(): void
    {
        $id  = (string) random_int(1000000000, 9999999999);
        $row = $this->seed($id, 'Mozilla/5.0 (the agent that got there first)');

        $this->assertTrue($row->wasPersisted(), 'the fixture row should have loaded');

        $this->assertTrue(
            $row->detectIdCollision('ua', 'Mozilla/5.0 (a completely different agent)'),
            'a row found by id but holding other content is a collision and must be reported'
        );
    }

    public function testTheOrdinaryCaseIsNotReported(): void
    {
        $content = 'Mozilla/5.0 (the one and only agent)';
        $id      = (string) random_int(1000000000, 9999999999);
        $row     = $this->seed($id, $content);

        $this->assertFalse(
            $row->detectIdCollision('ua', $content),
            'reuse of a row that holds exactly the content it was derived from is the normal path'
        );
    }

    /**
     * A row that does not exist is not evidence of anything. Reporting here
     * would fire on every first sighting of every dimension value, which is
     * most of them on a new installation.
     */
    public function testAnAbsentRowIsNotACollision(): void
    {
        $missing = \OWA\Core\CoreAPI::entityFactory('base.ua');
        $missing->load((string) random_int(1000000000, 9999999999));

        $this->assertNotTrue($missing->wasPersisted(), 'fixture should not exist');
        $this->assertFalse($missing->detectIdCollision('ua', 'anything at all'));
    }

    /** Nothing to compare means nothing to conclude. */
    public function testEmptyContentOnEitherSideIsNotACollision(): void
    {
        $id  = (string) random_int(1000000000, 9999999999);
        $row = $this->seed($id, 'Mozilla/5.0 (present)');

        $this->assertFalse($row->detectIdCollision('ua', ''));
        $this->assertFalse($row->detectIdCollision('ua', null));
    }

    /** The report has to say enough to act on: which table, which id, both values. */
    public function testTheReportNamesTheTableTheIdAndBothValues(): void
    {
        $id    = (string) random_int(1000000000, 9999999999);
        $first = 'Mozilla/5.0 (first agent)';
        $other = 'Mozilla/5.0 (second agent)';
        $row   = $this->seed($id, $first);

        // CoreAPI::notice() goes through OWA's error handler to OWA's OWN log
        // file, not PHP's error_log, so capture is a matter of reading what got
        // appended to it.
        $logs = glob(OWA_DATA_DIR . 'logs/errors_*.txt');

        if (!$logs) {
            $this->markTestSkipped('no OWA error log on this installation to read the notice back from');
        }

        $log    = $logs[0];
        $before = (int) filesize($log);

        $row->detectIdCollision('ua', $other);

        clearstatcache(true, $log);
        $written = (string) file_get_contents($log, false, null, $before);

        $this->assertStringContainsString($id, $written, 'the id is what you would search the table for');
        $this->assertStringContainsString($first, $written, 'the stored value must be named');
        $this->assertStringContainsString($other, $written, 'the colliding value must be named');
    }
}
