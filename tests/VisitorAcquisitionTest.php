<?php

require_once __DIR__ . '/IngestionTestCase.php';

/**
 * Acquisition is the FIRST session's traffic source, recorded on the visitor.
 *
 * owa_visitor held when a visitor first arrived and nothing about where from,
 * so first-touch attribution was not a reportable thing at all. The `original`
 * attribution mode approximates it with a sliding 60-day window, which answers
 * a different question under the same name.
 *
 * The property that makes this attribution rather than a rolling copy is that
 * it is written ONCE, in VisitorHandlers' `! wasPersisted()` branch, and never
 * revisited -- so the interesting test here is not that a value is stored, it
 * is that a later visit through a different campaign does not move it.
 */
final class VisitorAcquisitionTest extends IngestionTestCase
{
    private const COLUMNS = [
        'first_session_source',
        'first_session_medium',
        'first_session_campaign',
        'first_session_ad',
        'first_session_search_terms',
    ];

    /** @return array<string, mixed>|false */
    private function visitorRow(string $visitorId)
    {
        $db = owa_coreAPI::dbSingleton();

        return $db->get_row(sprintf(
            'SELECT %s FROM owa_visitor WHERE id = %d',
            implode(', ', self::COLUMNS),
            (int) $visitorId
        ));
    }

    private function fireTaggedRequest(string $visitorId, string $campaign, string $source): void
    {
        $result = $this->fireEvent('base.page_request', [
            'guid'            => (string) random_int(1000000000, 9999999999),
            'site_id'         => md5('owa-test-site'),
            'session_id'      => (string) random_int(1000000000000, 9999999999999),
            'page_url'        => 'https://owa-test-site.test/acquisition-probe',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
            'ip_address'      => '203.0.113.202',
            'is_new_session'  => true,
            'is_new_visitor'  => true,
            'visitor_id'      => $visitorId,
            'landing_url'     => $this->landingUrlWithTags(
                'https://owa-test-site.test/acquisition-probe',
                array( 'campaign' => $campaign, 'source' => $source, 'medium' => 'cpc' ) ),
        ]);

        $this->assertNotFalse($result, 'page_request was dropped before persistence.');
    }

    /** The entity has to declare them or nothing else here can be true. */
    public function testEntityDeclaresTheAcquisitionColumns(): void
    {
        $entity  = owa_coreAPI::entityFactory('base.visitor');
        $columns = $entity->getColumns();

        foreach (self::COLUMNS as $column) {
            $this->assertContains($column, $columns, "base.visitor must declare $column");
        }
    }

    /** A new visitor's acquisition is the session that created them. */
    public function testAcquisitionIsWrittenWhenTheVisitorIsCreated(): void
    {
        $visitorId = (string) random_int(1000000000, 9999999999);

        $this->fireTaggedRequest($visitorId, 'spring-sale', 'newsletter');

        $row = $this->visitorRow($visitorId);

        $this->assertNotFalse($row, 'no visitor row was written');
        $this->assertSame('newsletter',  $row['first_session_source']);
        $this->assertSame('cpc',         $row['first_session_medium']);
        $this->assertSame('spring-sale', $row['first_session_campaign']);
    }

    /**
     * THE LOAD-BEARING ONE. A later campaign must not move acquisition.
     *
     * Without the write-once branch this stores last-touch under a first-touch
     * name, which is worse than not having the column: every report built on it
     * would be quietly wrong in a way no one would think to check. The second
     * request carries a different campaign, source and session, so if anything
     * rewrote the row this assertion could not pass by accident.
     */
    public function testAcquisitionDoesNotChangeWhenTheVisitorReturns(): void
    {
        $visitorId = (string) random_int(1000000000, 9999999999);

        $this->fireTaggedRequest($visitorId, 'spring-sale',  'newsletter');
        $before = $this->visitorRow($visitorId);

        $this->fireTaggedRequest($visitorId, 'autumn-clear', 'partner-site');
        $after = $this->visitorRow($visitorId);

        $this->assertNotFalse($before);
        $this->assertNotFalse($after);

        $this->assertSame('newsletter',  $after['first_session_source'],
            'a returning visitor must keep the source that first acquired them');
        $this->assertSame('spring-sale', $after['first_session_campaign'],
            'a returning visitor must keep the campaign that first acquired them');

        foreach (self::COLUMNS as $column) {
            $this->assertSame($before[$column], $after[$column], "$column must be write-once");
        }
    }

    /**
     * The backfill must not carry the legacy "(not set)" sentinel forward.
     *
     * owa_source_dim still holds that string in rows written before Update031,
     * which cleared only the columns a registered dimension resolves through.
     * Copying it into a brand new column would reintroduce exactly what that
     * migration removed, and would leave this column holding two spellings of
     * absence from its first day -- a GROUP BY would then draw two buckets that
     * both mean "unknown" and both render as "(not set)".
     *
     * Exercised through the command rather than by reading its SQL, because a
     * source-scanning assertion here would pass whether or not the mapping
     * actually reached the database.
     */
    public function testBackfillStoresNullRatherThanTheAbsenceSentinel(): void
    {
        $db = owa_coreAPI::dbSingleton();

        $visitorId = random_int(1000000000, 9999999999);
        $sessionId = random_int(1000000000000, 9999999999999);
        $sourceId  = random_int(1000000000, 9999999999);

        $db->query(sprintf(
            'INSERT INTO owa_source_dim (id, source_domain) VALUES (%d, "(not set)")', $sourceId));
        $db->query(sprintf(
            'INSERT INTO owa_session (id, site_id, source_id, medium) VALUES (%d, "%s", %d, "(not set)")',
            $sessionId, md5('owa-test-site'), $sourceId));
        $db->query(sprintf(
            'INSERT INTO owa_visitor (id, first_session_id) VALUES (%d, %d)', $visitorId, $sessionId));

        /*
         * Run as a subprocess, the way an operator runs it.
         *
         * Constructing the controller in-process ends the PHPUnit run: it is an
         * AdminController and the CLI path terminates when it is done, so the
         * suite stopped after this test with an exit status of 0 and no summary
         * -- every later test silently not run, reported as success.
         */
        $cmd = escapeshellarg(PHP_BINARY) . ' '
             . escapeshellarg(OWA_DIR . 'cli.php')
             . ' cmd=backfill-visitor-acquisition 2>&1';

        shell_exec($cmd);

        $row = $this->visitorRow((string) $visitorId);

        $this->assertNotFalse($row);
        $this->assertNull($row['first_session_source'],
            'the sentinel must not be copied into a new column');
        $this->assertNull($row['first_session_medium'],
            'and the same for medium, which is copied straight off the session');

        $db->query(sprintf('DELETE FROM owa_visitor WHERE id = %d', $visitorId));
        $db->query(sprintf('DELETE FROM owa_session WHERE id = %d', $sessionId));
        $db->query(sprintf('DELETE FROM owa_source_dim WHERE id = %d', $sourceId));
    }

    /**
     * Both write paths must spell absence the same way.
     *
     * Both spell it NULL, which is v2's form and needs an opt-in to get:
     * Entity::writeValue() stores '' for an unset text column unless the
     * column declares itself nullable, because columns that predate the PDO
     * driver have to keep the shape it gave them. These five do not predate
     * it, so they are declared nullable and absence is NULL throughout.
     *
     * Worth a test of its own because one column holding both spellings is
     * invisible to either path's own tests -- each is self-consistent, and the
     * damage only shows in a GROUP BY, as two buckets that mean the same thing
     * and both render as "(not set)".
     */
    public function testAbsenceIsNullNotEmptyString(): void
    {
        $visitorId = (string) random_int(1000000000, 9999999999);

        $result = $this->fireEvent('base.page_request', [
            'guid'            => (string) random_int(1000000000, 9999999999),
            'site_id'         => md5('owa-test-site'),
            'session_id'      => (string) random_int(1000000000000, 9999999999999),
            'page_url'        => 'https://owa-test-site.test/direct-acquisition-probe',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7)',
            'ip_address'      => '203.0.113.203',
            'is_new_session'  => true,
            'is_new_visitor'  => true,
            'visitor_id'      => $visitorId,
        ]);

        $this->assertNotFalse($result, 'direct page_request was dropped before persistence.');

        $row = $this->visitorRow($visitorId);

        $this->assertNotFalse($row);

        foreach (['first_session_source', 'first_session_campaign',
                  'first_session_ad', 'first_session_search_terms'] as $column) {

            $this->assertNull($row[$column],
                "$column must spell absence as NULL -- the backfill writes NULL too, and one "
                . 'column holding both NULL and empty draws two buckets that mean the same thing');
        }

        $this->assertSame('direct', $row['first_session_medium'],
            "medium's default is a real answer, not an absence");
    }

    /**
     * Registered against base.visitor, and NOT against the fact tables.
     *
     * Registering them on the fact row would make them look like the session's
     * own source/medium and let the two be combined in ways that mean nothing.
     * Visitor scope is what makes "revenue by acquisition source" a different
     * question from "revenue by source".
     */
    public function testAcquisitionDimensionsAreRegisteredAgainstTheVisitor(): void
    {
        $expected = [
            'acquisitionSource'      => 'first_session_source',
            'acquisitionMedium'      => 'first_session_medium',
            'acquisitionCampaign'    => 'first_session_campaign',
            'acquisitionAd'          => 'first_session_ad',
            'acquisitionSearchTerms' => 'first_session_search_terms',
        ];

        foreach ($expected as $name => $column) {

            $dim = \OWA\Core\CoreAPI::serviceSingleton()->getDimension($name, 'base.visitor');

            $this->assertNotEmpty($dim, "$name must be registered against base.visitor");
            $this->assertSame($column, $dim['column'], "$name must read $column");
            $this->assertSame('base.visitor', $dim['entity']);
        }
    }
}
