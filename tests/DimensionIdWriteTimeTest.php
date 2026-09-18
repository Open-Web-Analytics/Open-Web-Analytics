<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;

/**
 * A fact derives its dimension keys when it is written, from content.
 *
 * Previously the tracking-property pipeline hashed them onto the event before
 * dispatch and Entity::setProperties() copied the result into the column. The
 * derivation therefore lived upstream of every handler, where v2 would have had
 * to pay for it, and where it had drifted into three disagreeing copies.
 */
final class DimensionIdWriteTimeTest extends TestCase
{
    private function request()
    {
        return \OWA\Core\CoreAPI::entityFactory( 'base.request' );
    }

    private function session()
    {
        return \OWA\Core\CoreAPI::entityFactory( 'base.session' );
    }

    /** Content in, id out -- no pre-derived id required anywhere in the bag. */
    public function testAFactDerivesItsKeyFromContentAlone(): void
    {
        $fact = $this->request();
        $fact->setProperties( array( 'page_url' => 'https://example.test/p' ) );

        $this->assertSame(
            (string) \OWA\Module\Base\Entity\Document::deriveId( array( 'page_url' => 'https://example.test/p' ) ),
            (string) $fact->get( 'document_id' ) );
    }

    /**
     * Content wins over a pre-derived id sitting in the same bag.
     *
     * This is what makes the pipeline's copy removable: while both run, the
     * derived value is the one that lands, so deleting the upstream derivation
     * changes nothing. It also means a handler that alters content before a
     * fact is written cannot leave a stale key behind.
     */
    public function testContentBeatsAStaleIdInTheBag(): void
    {
        $fact = $this->request();
        $fact->setProperties( array(
            'page_url'    => 'https://example.test/real',
            'document_id' => '1111111111111111111',
        ) );

        $this->assertSame(
            (string) \OWA\Module\Base\Entity\Document::deriveId( array( 'page_url' => 'https://example.test/real' ) ),
            (string) $fact->get( 'document_id' ) );

        $this->assertNotSame( '1111111111111111111', (string) $fact->get( 'document_id' ) );
    }

    /**
     * A bag that does not carry the content leaves the column alone.
     *
     * "The content says there is no value" and "this bag is not about this
     * dimension" are different. Several call sites pass a partial bag -- a
     * session update, a re-dispatched event -- and re-deriving from one of those
     * overwrote a correct id with the unresolved one. Caught by
     * DimensionAssociationTest, which saw a fact pointing at a document row
     * whose url was '(not set)'.
     */
    public function testAPartialBagDoesNotClobberAnAlreadyDerivedKey(): void
    {
        $fact = $this->request();
        $fact->setProperties( array( 'page_url' => 'https://example.test/keep' ) );

        $good = (string) $fact->get( 'document_id' );
        $this->assertNotSame( '', $good );

        // A later, unrelated update that says nothing about the document.
        $fact->setProperties( array( 'num_prior_sessions' => 3 ) );

        $this->assertSame( $good, (string) $fact->get( 'document_id' ),
            'a bag with no page_url overwrote the document key' );
    }

    /** But content that IS present and empty is authoritative: that is absence. */
    public function testEmptyContentIsAbsenceNotSilence(): void
    {
        $fact = $this->request();
        $fact->setProperties( array( 'page_url' => 'https://example.test/x' ) );
        $fact->setProperties( array( 'page_url' => '' ) );

        $this->assertSame(
            (string) \OWA\Module\Base\Entity\Document::deriveId( array() ),
            (string) $fact->get( 'document_id' ),
            'an explicitly empty page_url should resolve to the unresolved row' );
    }

    /**
     * Only the dimension's canonical column is derived.
     *
     * owa_session carries first_page_id AND last_page_id, both foreign keys into
     * base.document but meaning different pages. Deriving every column that
     * points at a dimension would make them permanently equal and destroy the
     * session's entry and exit page.
     */
    public function testASecondForeignKeyIntoTheSameDimensionIsLeftAlone(): void
    {
        $session = $this->session();

        $session->set( 'first_page_id', '2222222222222222222' );
        $session->set( 'last_page_id',  '3333333333333333333' );

        $session->setProperties( array( 'page_url' => 'https://example.test/current' ) );

        $this->assertSame( '2222222222222222222', (string) $session->get( 'first_page_id' ) );
        $this->assertSame( '3333333333333333333', (string) $session->get( 'last_page_id' ) );

        // owa_session carries no document_id of its own -- the canonical column
        // lives on owa_request -- so assert the derivation still happens there,
        // from the same bag that left the session's two page keys alone.
        $request = $this->request();
        $request->setProperties( array( 'page_url' => 'https://example.test/current' ) );

        $this->assertSame(
            (string) \OWA\Module\Base\Entity\Document::deriveId( array( 'page_url' => 'https://example.test/current' ) ),
            (string) $request->get( 'document_id' ),
            'document_id is the canonical column and should have been derived' );
    }

    /**
     * A foreign key whose target is not a content-derived dimension is copied,
     * as it always was. site_id is a minted identifier and visitor_id comes from
     * the tracker; neither is a hash of anything on the event.
     */
    public function testNonDimensionForeignKeysAreStillCopied(): void
    {
        $fact = $this->request();
        $fact->setProperties( array(
            'site_id'    => 'OWA-0123456789abcdef',
            'visitor_id' => '7770000000000001',
        ) );

        $this->assertSame( 'OWA-0123456789abcdef', (string) $fact->get( 'site_id' ) );
        $this->assertSame( '7770000000000001',     (string) $fact->get( 'visitor_id' ) );
    }

    /**
     * A dimension that does not apply leaves its column unset rather than
     * pointing at a row that names nothing.
     */
    public function testANotApplicableDimensionSetsNoKey(): void
    {
        $fact = $this->request();
        $fact->setProperties( array( 'campaign' => '' ) );

        $this->assertEmpty( $fact->get( 'campaign_id' ),
            'untagged traffic should carry no campaign key' );
    }
}
