<?php

use PHPUnit\Framework\TestCase;

/**
 * A value longer than its column is trimmed on the way in, not on the way out.
 *
 * MySQL used to do this, silently, and OWA depended on it without saying so.
 * Measured on a live install, the dependency is heavy rather than incidental:
 *
 *   owa_document.url    135 / 874     15.4% sitting at exactly the limit
 *   owa_referer.url   2,429 / 25,454   9.5%
 *   owa_document.uri     52 / 874      5.9%
 *   owa_ua.ua           180 / 39,953   0.45%
 *
 * A row at exactly the limit is the fingerprint of a value that arrived longer.
 *
 * Under a permissive sql_mode that trim is a warning nobody reads. Under
 * STRICT_ALL_TABLES it is an error and the INSERT is refused outright -- so the
 * page view is not truncated, it is lost, and the write path does not surface
 * the failure, so it is lost quietly. That is why this is a correctness fix and
 * not tidying: it is the thing standing between OWA and a strict sql_mode.
 */
final class ColumnLengthHealTest extends TestCase {

    public static function setUpBeforeClass(): void {

        require_once __DIR__ . '/bootstrap_owa.php';
    }

    private function column( $type ) {

        return new \OWA\Module\Base\Classes\DbColumn( 'probe', $type );
    }

    public function testAnOverLongValueIsTrimmedToTheColumnLength(): void {

        $c = $this->column( OWA_DTD_VARCHAR255 );
        $c->setValue( str_repeat( 'x', 300 ) );

        $this->assertSame( 255, mb_strlen( $c->getValue(), 'UTF-8' ) );
    }

    public function testAShorterColumnUsesItsOwnLength(): void {

        $c = $this->column( OWA_DTD_VARCHAR10 );
        $c->setValue( str_repeat( 'x', 40 ) );

        $this->assertSame( 10, mb_strlen( $c->getValue(), 'UTF-8' ),
            'the length must come from the column, not from a single global maximum' );
    }

    public function testAValueThatFitsIsUntouched(): void {

        $c = $this->column( OWA_DTD_VARCHAR255 );
        $c->setValue( 'https://example.com/a-normal-url' );

        $this->assertSame( 'https://example.com/a-normal-url', $c->getValue() );
    }

    /**
     * Trimming by bytes would cut a UTF-8 sequence in half. Strict mode rejects
     * the result of THAT too, so a byte-wise fix would trade one silent loss
     * for another -- and this test is the only thing that tells them apart,
     * since both produce a value of roughly the right size.
     */
    public function testMultibyteValuesAreCutOnCharacterBoundaries(): void {

        $c = $this->column( OWA_DTD_VARCHAR255 );
        $c->setValue( str_repeat( '日', 300 ) );

        $value = $c->getValue();

        $this->assertSame( 255, mb_strlen( $value, 'UTF-8' ), 'the column counts characters' );
        $this->assertSame( $value, mb_convert_encoding( $value, 'UTF-8', 'UTF-8' ),
            'trimming must not leave a partial character behind' );
    }

    public function testNonStringsAndUnboundedTypesPassThrough(): void {

        foreach ( [ 0, 1, false, true, null, 12345 ] as $value ) {

            $c = $this->column( OWA_DTD_VARCHAR255 );
            $c->setValue( $value );

            $this->assertSame( $value, $c->getValue(), 'non-strings must not be touched' );
        }

        $text = $this->column( OWA_DTD_TEXT );
        $long = str_repeat( 'x', 5000 );
        $text->setValue( $long );

        $this->assertSame( $long, $text->getValue(),
            'a text column has no declared limit here and must not be trimmed' );
    }

    /**
     * Same portability property as the numeric-type check: the lengths are keyed
     * off the OWA_DTD_* constants, so a dialect that spells VARCHAR differently
     * still resolves. A hard-coded 'VARCHAR(255)' would fail this.
     */
    public function testTheLengthMapIsKeyedOffDeclaredTypes(): void {

        $c = $this->column( OWA_DTD_VARCHAR255 );

        $m = new ReflectionMethod( $c, 'maxLength' );
        $m->setAccessible( true );

        $this->assertSame( 255, $m->invoke( $c ),
            'the declared VARCHAR255 type must resolve to its length through the constant' );

        $unknown = $this->column( 'SOMETHING_NO_DIALECT_DECLARES' );
        $m2 = new ReflectionMethod( $unknown, 'maxLength' );
        $m2->setAccessible( true );

        $this->assertSame( 0, $m2->invoke( $unknown ),
            'an unrecognised type must report no limit rather than guessing one' );
    }

    /**
     * The point of the whole change: an entity write that strict mode would have
     * refused now succeeds. Uses a TEMPORARY table so nothing real is touched,
     * and sets the strict mode explicitly so the test does not depend on the
     * installation's configured default.
     */
    public function testAnOverLongValueSurvivesAWriteUnderStrictMode(): void {

        if ( ! owa_test_db_available() ) {

            $this->markTestSkipped( 'No database available.' );
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $db->query( 'CREATE TEMPORARY TABLE heal_probe (id BIGINT, page_title VARCHAR(255))' );
        $db->query( "SET SESSION sql_mode = 'STRICT_ALL_TABLES'" );

        $c = $this->column( OWA_DTD_VARCHAR255 );
        $c->setValue( str_repeat( 'z', 300 ) );

        $ok = $db->query( sprintf(
            "INSERT INTO heal_probe (id, page_title) VALUES (1, '%s')",
            $c->getValue()
        ) );

        $row = $db->get_row( 'SELECT COUNT(*) AS n, CHAR_LENGTH(page_title) AS len FROM heal_probe' );

        $db->query( "SET SESSION sql_mode = ''" );

        $this->assertSame( 1, (int) $row['n'],
            'strict mode refused the row -- the value was not healed before the write' );
        $this->assertSame( 255, (int) $row['len'] );
    }

    /**
     * An identity-bearing column can opt out.
     *
     * Trimming a value that something derives a key from is not the same
     * operation as trimming one nobody reads back: the stored value stops
     * deriving to the id that names it. Nothing sets this today -- the database
     * was trimming those columns anyway -- but the flag is what lets such a
     * column be handled deliberately rather than disappearing into the general
     * case.
     */
    public function testAColumnCanOptOutOfBeingTrimmed(): void
    {
        $c = $this->column( OWA_DTD_VARCHAR255 );
        $c->setTruncatable( false );

        $long = str_repeat( 'x', 300 );
        $c->setValue( $long );

        $this->assertSame( $long, $c->getValue(),
            'a column marked not-truncatable must keep the value it was given' );
    }

    public function testColumnsAreTruncatableByDefault(): void
    {
        $c = $this->column( OWA_DTD_VARCHAR255 );

        $this->assertTrue( $c->get( 'truncatable' ),
            'the default must match what the database was already doing' );
    }

    /**
     * The emoji case, which is not exotic -- emoji in page titles is ordinary.
     *
     * OWA declares utf8, which in MySQL holds nothing above U+FFFF. What MySQL
     * does with an unstorable character is not drop it, it drops THE REST OF
     * THE VALUE: a 35-character title with one emoji in the middle stores as 13
     * characters, silently, under the permissive mode. Dropping the character
     * costs one character instead of the remainder.
     */
    public function testAnUnstorableCharacterCostsOneCharacterNotTheRest(): void
    {
        $c = $this->column( OWA_DTD_VARCHAR255 );
        $c->setValue( "Sales report \xF0\x9F\x93\x8A Q3 summary and notes" );

        $this->assertStringContainsString( 'Q3 summary and notes', $c->getValue(),
            'everything after the unstorable character must survive' );
        $this->assertStringNotContainsString( "\xF0\x9F\x93\x8A", $c->getValue() );
    }

    public function testThreeByteCharactersAreNotDisturbed(): void
    {
        $c = $this->column( OWA_DTD_VARCHAR255 );
        $c->setValue( '日本語のページタイトル' );

        $this->assertSame( '日本語のページタイトル', $c->getValue(),
            'characters the encoding CAN hold must be left alone' );
    }

    /**
     * Order matters: the length is what will be stored, so it has to be
     * measured after the unstorable characters are gone, not before.
     */
    public function testLengthIsMeasuredAfterUnstorableCharactersAreRemoved(): void
    {
        $c = $this->column( OWA_DTD_VARCHAR10 );
        // 4 emoji then 10 ascii: 14 characters, 10 of them storable.
        $c->setValue( str_repeat( "\xF0\x9F\x93\x8A", 4 ) . 'abcdefghij' );

        $this->assertSame( 'abcdefghij', $c->getValue(),
            'stripping first is what leaves a full ten storable characters' );
    }

    /**
     * A dialect whose UTF-8 is not width-limited -- PostgreSQL's, for one --
     * must keep the character. The check is on the DECLARED encoding, so this
     * stops doing anything once the schema moves to utf8mb4.
     */
    public function testNothingIsStrippedWhenTheEncodingIsNotWidthLimited(): void
    {
        $c = new class( 'probe', OWA_DTD_VARCHAR255 ) extends \OWA\Module\Base\Classes\DbColumn {
            protected function encodingIsWidthLimited() { return false; }
        };

        $value = "keeps \xF0\x9F\x93\x8A the emoji";
        $c->setValue( $value );

        $this->assertSame( $value, $c->getValue() );
    }

    /**
     * End to end: the row that strict mode would refuse now lands, holding
     * everything except the one character that could never have been stored.
     */
    public function testAnEmojiValueSurvivesAWriteUnderStrictMode(): void
    {
        if ( ! owa_test_db_available() ) {
            $this->markTestSkipped( 'No database available.' );
        }

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->query( 'CREATE TEMPORARY TABLE mb4_heal_probe (id BIGINT, page_title VARCHAR(255))' );
        $db->query( "SET SESSION sql_mode = 'STRICT_ALL_TABLES'" );

        $c = $this->column( OWA_DTD_VARCHAR255 );
        $c->setValue( "Sales report \xF0\x9F\x93\x8A Q3 summary and notes" );

        $db->query( sprintf(
            "INSERT INTO mb4_heal_probe (id, page_title) VALUES (1, '%s')", $c->getValue() ) );

        $row = $db->get_row( 'SELECT COUNT(*) AS n, page_title AS t FROM mb4_heal_probe' );
        $db->query( "SET SESSION sql_mode = ''" );

        $this->assertSame( 1, (int) $row['n'],
            'strict mode refused the row -- the unstorable character was not removed first' );
        $this->assertStringContainsString( 'Q3 summary and notes', (string) $row['t'],
            'the text after the emoji must be in the stored row' );
    }
}
