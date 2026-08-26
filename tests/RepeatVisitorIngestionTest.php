<?php

require_once __DIR__ . '/IngestionTestCase.php';

/**
 * A tracked NEW visitor stores is_repeat_visitor = 0, not NULL.
 *
 * The unit-level fix -- setRepeatVisitorFlag returning false rather than
 * falling off the end -- only matters if false actually reaches storage as 0.
 * This install's own owa_session table is the evidence that it did not: the
 * rows this very suite produced are the NULL ones.
 *
 * NULL is a THIRD value for a two-state fact. Anything GROUPing on the column
 * gets three buckets, which is how the dashboard drew two separate slices both
 * labelled "No".
 */
final class RepeatVisitorIngestionTest extends IngestionTestCase
{
    private string $sessionId = '';

    private function fireNewVisitorPageRequest(): void
    {
        /*
         * A session row is only written when the event says it STARTED one and
         * carries the session_id to write it under -- see SessionHandlers. An
         * event without both is handled successfully and stores no session, so
         * a test that omitted them would assert against whatever row happened
         * to be newest and pass or fail for reasons unrelated to the flag.
         */
        $this->sessionId = (string) random_int(1000000000000, 9999999999999);

        $this->fireEvent('base.page_request', [
            'site_id'             => md5('owa-test-site'),
            'page_url'            => 'https://owa-test-site.test/repeat-visitor-probe',
            'guid'                => (string) random_int(1000000000, 9999999999),
            'ip_address'          => '203.0.113.201',
            'session_id'          => $this->sessionId,
            'is_new_session_start'=> true,
            // The visitor is NEW, which is the branch that used to yield null.
            'is_new_visitor'      => true,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>> the newest session rows for the fixture site
     */
    private function recentSessions( int $limit = 3 ): array
    {
        $db = owa_coreAPI::dbSingleton();
        $db->selectFrom('owa_session');
        $db->selectColumn('is_repeat_visitor, is_new_visitor, timestamp');
        $db->where('site_id', md5('owa-test-site'));
        $db->orderBy('timestamp', 'DESC');
        $db->limit($limit);

        return (array) $db->getAllRows();
    }

    /**
     * The schema does not enforce this, so a test has to.
     *
     * `is_repeat_visitor` is nullable with no default, so nothing stops a
     * write path leaving NULL -- and NULL is a third value for a two-state
     * fact. Making the column NOT NULL would mean an ALTER over every install
     * session table, which is a far heavier promise than this invariant needs.
     *
     * Scoped to sessions this test just wrote: the table on a long-lived
     * install carries history from before the derivation was fixed, and this
     * is about what the code writes NOW.
     */
    public function testNoSessionWrittenNowHasANullFlag(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; this asserts what was STORED.');
        }

        $ids = array();

        // A new visitor and a returning one -- both branches of the derivation.
        foreach (array(true, false) as $isNew) {

            $sessionId = (string) random_int(1000000000000, 9999999999999);
            $ids[] = $sessionId;

            $this->fireEvent('base.page_request', [
                'site_id'              => md5('owa-test-site'),
                'page_url'             => 'https://owa-test-site.test/null-invariant',
                'guid'                 => (string) random_int(1000000000, 9999999999),
                'ip_address'           => '203.0.113.202',
                'session_id'           => $sessionId,
                'is_new_session_start' => true,
                'is_new_visitor'       => $isNew,
            ]);
        }

        foreach ($ids as $id) {

            $db = owa_coreAPI::dbSingleton();
            $db->selectFrom('owa_session');
            $db->selectColumn('is_repeat_visitor');
            $db->where('id', $id);

            $row = (array) $db->getOneRow();

            $this->assertNotEmpty($row, "session $id was not written");
            $this->assertNotNull($row['is_repeat_visitor'],
                'a write path left NULL in a two-state column; the schema does not stop it, so this does');
        }
    }

    public function testATrackedSessionStoresTheFlagAsZeroNotNull(): void
    {
        if (!owa_test_db_available()) {
            $this->markTestSkipped('OWA database not reachable; this asserts what was STORED.');
        }

        $this->fireNewVisitorPageRequest();

        $db = owa_coreAPI::dbSingleton();
        $db->selectFrom('owa_session');
        $db->selectColumn('is_repeat_visitor, is_new_visitor');
        $db->where('id', $this->sessionId);

        $row = (array) $db->getOneRow();

        // Assert on the row THIS test wrote. "The newest row" is what made an
        // earlier version of this pass against pre-existing data.
        $this->assertNotEmpty($row, 'the event must have produced a session to assert about');

        $stored = $row['is_repeat_visitor'];

        $this->assertNotNull($stored,
            'NULL is a third value for a two-state fact; it groups as its own bucket and '
            . 'reports as a second "No" slice');
        $this->assertSame(0, (int) $stored);
    }
}
