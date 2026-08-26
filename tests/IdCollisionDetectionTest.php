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

    /**
     * Capture what OWA logs while $fn runs.
     *
     * WHY NOT READ THE LOG FILE
     *
     * This test used to glob OWA_DATA_DIR/logs/errors_*.txt, take the first
     * match, and diff its length before and after. That made it depend on three
     * things that are properties of an INSTALLATION rather than of the code:
     *
     *   1. a log file already existing -- and when none did, the test SKIPPED
     *      rather than failed. A fresh checkout ships owa-data/logs/index.php
     *      and nothing else, and the file logger creates the log lazily on its
     *      first write, so whether this test RAN AT ALL on CI depended on
     *      whether something unrelated had logged first. A test whose execution
     *      is conditional on ambient state reports success for a claim it may
     *      never have checked.
     *   2. glob()[0] being the ACTIVE log. Two installs sharing owa-data/logs
     *      put two files in that directory and the first is not necessarily the
     *      one being written.
     *   3. the configured log level including notice, and setHandler() having
     *      been called -- before that, log() buffers instead of emitting.
     *
     * A test that silently skips is worse than one that fails: it reports
     * success for a claim it never checked.
     *
     * So the notice is captured at the logger instead, with Monolog's own
     * TestHandler at DEBUG level so nothing is filtered out. That is closer to
     * the code under test than the file was -- the line format is Monolog's
     * business, not this test's -- and it works on any install.
     *
     * @return array<int,array<string,mixed>> Monolog records
     */
    private function captureLog(callable $fn): array
    {
        $e = \OWA\Core\CoreAPI::errorSingleton();

        $handler = new \Monolog\Handler\TestHandler(\Monolog\Logger::DEBUG);
        $e->logger->pushHandler($handler);

        // Before setHandler() runs, log() BUFFERS rather than emitting, and
        // nothing would reach the handler above. The test bootstrap leaves this
        // true; forcing it means the test does not depend on that staying so.
        $was_init = $e->init;
        $e->init  = true;

        try {
            $fn();
        } finally {
            $e->init = $was_init;
            $e->logger->popHandler();
        }

        return $handler->getRecords();
    }

    /** The messages captured, as plain strings. */
    private function messages(array $records): string
    {
        return implode("\n", array_column($records, 'message'));
    }

    /** The report has to say enough to act on: which table, which id, both values. */
    public function testTheReportNamesTheTableTheIdAndBothValues(): void
    {
        $id    = (string) random_int(1000000000, 9999999999);
        $first = 'Mozilla/5.0 (first agent)';
        $other = 'Mozilla/5.0 (second agent)';
        $row   = $this->seed($id, $first);

        $records = $this->captureLog(function () use ($row, $other) {
            $row->detectIdCollision('ua', $other);
        });

        $written = $this->messages($records);

        $this->assertNotSame('', $written, 'a collision must be reported somewhere');

        $this->assertStringContainsString($id, $written, 'the id is what you would search the table for');
        $this->assertStringContainsString($first, $written, 'the stored value must be named');
        $this->assertStringContainsString($other, $written, 'the colliding value must be named');
        $this->assertStringContainsString($row->getTableName(), $written,
            'the table is which one to go and look in');
    }

    /**
     * At notice level, not debug.
     *
     * A collision is silent and permanent, so the one chance of noticing it is
     * the log -- and debug is off on a production install, which is exactly
     * where this matters. The old test read a file and could not see the level
     * at all.
     */
    public function testTheCollisionIsReportedAtNoticeLevel(): void
    {
        $id  = (string) random_int(1000000000, 9999999999);
        $row = $this->seed($id, 'Mozilla/5.0 (first agent)');

        $records = $this->captureLog(function () use ($row) {
            $row->detectIdCollision('ua', 'Mozilla/5.0 (second agent)');
        });

        $this->assertNotEmpty($records);

        $levels = array_unique(array_column($records, 'level'));

        $this->assertSame(array(\Monolog\Logger::NOTICE), array_values($levels),
            'a collision is reported at notice, so it survives a production log level');
    }

    /**
     * The ordinary path stays SILENT.
     *
     * Reuse of a dimension row is the common case -- it happens for almost
     * every event -- so a detector that logged there would bury the collisions
     * it exists to surface. Only assertable now that the log can be captured;
     * the return value alone says nothing about what was written.
     */
    public function testTheOrdinaryCaseLogsNothing(): void
    {
        $content = 'Mozilla/5.0 (the one and only agent)';
        $id      = (string) random_int(1000000000, 9999999999);
        $row     = $this->seed($id, $content);

        $records = $this->captureLog(function () use ($row, $content) {
            $row->detectIdCollision('ua', $content);
        });

        $this->assertSame(array(), $records,
            'reusing a row that holds its own content must not say anything');
    }

    /**
     * A long value is truncated, but the id never is.
     *
     * A user-agent can be arbitrarily long and it arrives from a request
     * header, so an untruncated pair would let a caller decide how many
     * kilobytes go into the log. The id is what makes the entry actionable, so
     * it has to survive whatever the values do.
     */
    public function testLongValuesAreTruncatedButTheIdSurvives(): void
    {
        $id    = (string) random_int(1000000000, 9999999999);
        $long  = 'Mozilla/5.0 ' . str_repeat('x', 500);
        $other = 'Mozilla/5.0 ' . str_repeat('y', 500);
        $row   = $this->seed($id, $long);

        $records = $this->captureLog(function () use ($row, $other) {
            $row->detectIdCollision('ua', $other);
        });

        $written = $this->messages($records);

        $this->assertStringContainsString($id, $written);
        $this->assertStringNotContainsString(str_repeat('x', 200), $written,
            'the stored value is truncated');
        $this->assertStringNotContainsString(str_repeat('y', 200), $written,
            'the colliding value is truncated');

        // ...and enough of each is kept to recognise them.
        $this->assertStringContainsString(str_repeat('x', 100), $written);
        $this->assertStringContainsString(str_repeat('y', 100), $written);
    }
}
