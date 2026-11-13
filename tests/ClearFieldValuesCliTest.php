<?php

require_once __DIR__ . '/CliControllerTestCase.php';

/**
 * cmd=clear-field-values: form field values come out of stored clicks and
 * recordings, and nothing else does.
 *
 * Every database case runs with site_id= set to a site of its own, so the
 * command never touches rows it did not create.
 */
final class ClearFieldValuesCliTest extends CliControllerTestCase
{
    const CLASS_NAME = \OWA\Module\Base\Controller\ClearFieldValuesCli::class;
    const FILE       = 'Controller/ClearFieldValuesCli.php';

    /** @var string */
    private $site;

    /** @var int */
    private $nextId = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site   = 'clear-field-values-' . $this->tok;
        $this->nextId = 9200000000000000000 + random_int( 0, 999999 ) * 1000;
    }

    protected function tearDown(): void
    {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->query( 'DELETE FROM owa_click WHERE site_id = ?', array( $this->site ) );
        $db->query( 'DELETE FROM owa_domstream WHERE site_id = ?', array( $this->site ) );

        parent::tearDown();
    }

    private function addClick( string $tag, ?string $text, ?string $value ): int
    {
        $id = ++$this->nextId;

        $ok = \OWA\Core\CoreAPI::dbSingleton()->query(
            'INSERT INTO owa_click ( id, site_id, yyyymmdd, dom_element_tag, dom_element_text, dom_element_value )
             VALUES ( ?, ?, ?, ?, ?, ? )',
            array( $id, $this->site, (int) date( 'Ymd' ), $tag, $text, $value ) );

        $this->assertNotFalse( $ok, 'the fixture click could not be written' );

        return $id;
    }

    private function addRecording( array $events ): int
    {
        $id = ++$this->nextId;

        $ok = \OWA\Core\CoreAPI::dbSingleton()->query(
            'INSERT INTO owa_domstream ( id, site_id, yyyymmdd, domstream_guid, events )
             VALUES ( ?, ?, ?, ?, ? )',
            array( $id, $this->site, (int) date( 'Ymd' ), (string) $id, json_encode( $events ) ) );

        $this->assertNotFalse( $ok, 'the fixture recording could not be written' );

        return $id;
    }

    private function click( int $id ): array
    {
        return \OWA\Core\CoreAPI::dbSingleton()->get_row(
            'SELECT dom_element_text AS text, dom_element_value AS value FROM owa_click WHERE id = ?', array( $id ) );
    }

    private function recording( int $id ): array
    {
        $row = \OWA\Core\CoreAPI::dbSingleton()->get_row(
            'SELECT events FROM owa_domstream WHERE id = ?', array( $id ) );

        return json_decode( $row['events'], true );
    }

    private function runClear( array $params = array() ): array
    {
        return $this->runCommand( self::CLASS_NAME, self::FILE, $params + array( 'site_id' => $this->site ) )['data'];
    }

    public function testTheCommandIsRegistered(): void
    {
        $this->assertSame( 'base.clearFieldValuesCli', $this->commandClass( 'clear-field-values' ) );
    }

    public function testATypedValueIsClearedFromAClickOnAnInput(): void
    {
        $id = $this->addClick( 'input', 'typed@example.com', 'typed@example.com' );

        $this->runClear();

        $this->assertSame( array( 'text' => null, 'value' => null ), $this->click( $id ) );
    }

    public function testATagStoredInUpperCaseIsMatched(): void
    {
        $id = $this->addClick( 'INPUT', 'typed', null );

        $this->runClear();

        $this->assertNull( $this->click( $id )['text'] );
    }

    public function testALinkKeepsItsTextAndLosesItsValueAttribute(): void
    {
        $id = $this->addClick( 'a', 'Pricing', 'prefilled' );

        $this->runClear();

        $this->assertSame( array( 'text' => 'Pricing', 'value' => null ), $this->click( $id ) );
    }

    public function testAButtonKeepsItsLabelAndValue(): void
    {
        $id = $this->addClick( 'button', 'Buy now', 'buy' );

        $this->runClear();

        $this->assertSame( array( 'text' => 'Buy now', 'value' => 'buy' ), $this->click( $id ) );
    }

    public function testADryRunCountsAndWritesNothing(): void
    {
        $id = $this->addClick( 'input', 'typed', null );
        $this->addClick( 'button', 'Buy now', 'buy' );

        $data = $this->runClear( array( 'dry-run' => true ) );

        $this->assertSame( 1, $data['clicks_matched'] );
        $this->assertSame( 'typed', $this->click( $id )['text'] );
    }

    public function testAnotherSitesClicksAreLeftAlone(): void
    {
        $id = $this->addClick( 'input', 'typed', null );

        $this->runCommand( self::CLASS_NAME, self::FILE, array( 'site_id' => $this->site . '-other' ) );

        $this->assertSame( 'typed', $this->click( $id )['text'] );
    }

    public function testKeyPressesInARecordingLoseTheirKeysButStay(): void
    {
        $id = $this->addRecording( array(
            array( 'event_type' => 'dom.keypress', 'key_value' => 's', 'key_code' => 115,
                   'dom_element_value' => 's', 'dom_element_id' => 'email', 'dom_element_tag' => 'input', 'timestamp' => 1 ),
            array( 'event_type' => 'dom.movement', 'cursor_x' => 10, 'cursor_y' => 20, 'timestamp' => 2 ),
        ) );

        $data = $this->runClear();

        $this->assertSame( 1, $data['recordings_rewritten'] );
        $this->assertSame( array(
            array( 'event_type' => 'dom.keypress', 'dom_element_id' => 'email', 'dom_element_tag' => 'input', 'timestamp' => 1 ),
            array( 'event_type' => 'dom.movement', 'cursor_x' => 10, 'cursor_y' => 20, 'timestamp' => 2 ),
        ), $this->recording( $id ) );
    }

    public function testClicksInARecordingAreClearedLikeStoredClicks(): void
    {
        $id = $this->addRecording( array(
            array( 'event_type' => 'dom.click', 'dom_element_tag' => 'input', 'dom_element_text' => 'secret', 'dom_element_value' => 'secret' ),
            array( 'event_type' => 'dom.click', 'dom_element_tag' => 'a', 'dom_element_text' => 'Pricing', 'dom_element_value' => '' ),
            array( 'event_type' => 'dom.click', 'dom_element_tag' => 'button', 'dom_element_text' => 'Buy', 'dom_element_value' => 'buy' ),
        ) );

        $this->runClear();

        $this->assertSame( array(
            array( 'event_type' => 'dom.click', 'dom_element_tag' => 'input' ),
            array( 'event_type' => 'dom.click', 'dom_element_tag' => 'a', 'dom_element_text' => 'Pricing', 'dom_element_value' => '' ),
            array( 'event_type' => 'dom.click', 'dom_element_tag' => 'button', 'dom_element_text' => 'Buy', 'dom_element_value' => 'buy' ),
        ), $this->recording( $id ) );
    }

    public function testAPlaceholderIsNeitherCountedNorCleared(): void
    {
        $link  = $this->addClick( 'a', 'Pricing', '(not set)' );
        $input = $this->addClick( 'input', '(not set)', 'undefined' );
        $empty = $this->addClick( 'input', '', '' );

        $this->assertSame( 0, $this->runClear( array( 'dry-run' => true ) )['clicks_matched'] );

        $this->runClear();

        $this->assertSame( array( 'text' => 'Pricing', 'value' => '(not set)' ), $this->click( $link ) );
        $this->assertSame( array( 'text' => '(not set)', 'value' => 'undefined' ), $this->click( $input ) );
        $this->assertSame( array( 'text' => '', 'value' => '' ), $this->click( $empty ) );
    }

    public function testOnlyTheColumnHoldingAValueIsCleared(): void
    {
        $id = $this->addClick( 'input', 'typed', '(not set)' );

        $this->runClear();

        $this->assertSame( array( 'text' => null, 'value' => '(not set)' ), $this->click( $id ) );
    }

    public function testARecordingWhoseClicksHoldNoValueIsNotRewritten(): void
    {
        $events = array(
            array( 'event_type' => 'dom.click', 'dom_element_tag' => 'a', 'dom_element_text' => 'Pricing', 'dom_element_value' => '' ),
            array( 'event_type' => 'dom.click', 'dom_element_tag' => 'input', 'dom_element_text' => '(not set)', 'dom_element_value' => 'undefined' ),
        );
        $id = $this->addRecording( $events );

        $data = $this->runClear();

        $this->assertSame( 0, $data['recordings_rewritten'] );
        $this->assertSame( $events, $this->recording( $id ) );
    }

    public function testASecondRunRewritesNothing(): void
    {
        $this->addRecording( array( array( 'event_type' => 'dom.keypress', 'key_value' => 's', 'key_code' => 115 ) ) );
        $this->addClick( 'input', 'typed', null );

        $this->runClear();
        $data = $this->runClear();

        $this->assertSame( 0, $data['clicks_cleared'] );
        $this->assertSame( 0, $data['recordings_rewritten'] );
    }

    public function testARecordingThatIsNotJsonIsCountedAndLeftAsItIs(): void
    {
        $id = ++$this->nextId;
        \OWA\Core\CoreAPI::dbSingleton()->query(
            'INSERT INTO owa_domstream ( id, site_id, yyyymmdd, domstream_guid, events ) VALUES ( ?, ?, ?, ?, ? )',
            array( $id, $this->site, (int) date( 'Ymd' ), (string) $id, 'not json key_value' ) );

        $data = $this->runClear();

        $this->assertSame( 1, $data['recordings_unreadable'] );
        $row = \OWA\Core\CoreAPI::dbSingleton()->get_row( 'SELECT events FROM owa_domstream WHERE id = ?', array( $id ) );
        $this->assertSame( 'not json key_value', $row['events'] );
    }

    public function testAStreamWithNothingToClearIsReportedUnchanged(): void
    {
        $this->assertNull( \OWA\Module\Base\Controller\ClearFieldValuesCli::clearStream(
            json_encode( array( array( 'event_type' => 'dom.movement', 'cursor_x' => 1 ) ) ) ) );
    }

    public function testAnEmptyObjectInAStreamStaysAnObject(): void
    {
        $out = \OWA\Module\Base\Controller\ClearFieldValuesCli::clearStream(
            '[{"event_type":"dom.keypress","key_value":"a","meta":{}}]' );

        $this->assertSame( '[{"event_type":"dom.keypress","meta":{}}]', $out );
    }
}
