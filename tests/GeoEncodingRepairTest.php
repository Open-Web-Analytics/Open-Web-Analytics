<?php

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\GeoEncodingRepair;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * Recognising a name that was encoded as UTF-8 twice, and undoing it. Issue #742.
 *
 * Undoing a mis-encoding means rewriting stored data, and a rule that is
 * slightly too eager corrupts rows that were fine. There is no undo, so the half
 * of this suite that matters is the half asserting what must NOT be touched.
 *
 * It also covers the repair command's PLANNING step, which turns a set of rows
 * into the list of changes it would make. That step needs no database, and
 * keeping it testable here means the decision about what to write is covered
 * everywhere, while only the writing itself waits for the CI isolation sweep.
 */
final class GeoEncodingRepairTest extends TestCase
{
    /*
     * ---------------------------------------------------------------------
     * The values that MUST be repaired.
     * ---------------------------------------------------------------------
     */

    public function testDoubleEncodedGermanNamesAreRecognisedAndUndone(): void
    {
        $cases = array(
            'MÃ¼nchen'           => 'München',
            'KÃ¶ln'              => 'Köln',
            'NÃ¼rnberg'          => 'Nürnberg',
            'DÃ¼sseldorf'        => 'Düsseldorf',
            'Baden-WÃ¼rttemberg' => 'Baden-Württemberg',
            'SaarbrÃ¼cken'       => 'Saarbrücken',
            'ThÃ¼ringen'         => 'Thüringen',
            'OsnabrÃ¼ck'         => 'Osnabrück',
        );

        foreach ( $cases as $stored => $intended ) {

            $this->assertSame( $intended, GeoEncodingRepair::repair( $stored ),
                sprintf( '%s should be repaired to %s', $stored, $intended ) );
        }
    }

    public function testItIsNotOnlyGermanThatWasAffected(): void
    {
        // Any name in the Latin-1 range was mangled the same way, which is why
        // the fix is not umlaut-specific.
        $cases = array(
            'ZÃ¼rich'    => 'Zürich',
            'MÃ¡laga'    => 'Málaga',
            'OrlÃ©ans'   => 'Orléans',
            'GÃ¶teborg'  => 'Göteborg',
            'TromsÃ¸'    => 'Tromsø',
            'ReykjavÃ­k' => 'Reykjavík',
            'SÃ£o Paulo' => 'São Paulo',
            'CuraÃ§ao'   => 'Curaçao',
        );

        foreach ( $cases as $stored => $intended ) {

            $this->assertSame( $intended, GeoEncodingRepair::repair( $stored ) );
        }
    }

    /*
     * ---------------------------------------------------------------------
     * The values that must NOT be touched. This is the important half.
     * ---------------------------------------------------------------------
     */

    public function testAnAlreadyCorrectNameIsLeftAlone(): void
    {
        // Written after the fix, or repaired by an earlier run of the command.
        // Rewriting these is how a repair turns into a second round of damage.
        foreach ( array( 'münchen', 'köln', 'Zürich', 'São Paulo', 'Curaçao',
                         'Malmö', 'Tromsø', 'Reykjavík' ) as $correct ) {

            $this->assertNull( GeoEncodingRepair::repair( $correct ),
                sprintf( '%s is already correct and must not be rewritten', $correct ) );
        }
    }

    /**
     * The case that makes the detection non-trivial.
     *
     * A genuine name in the Latin-1 range and a double-encoded one both contain
     * non-ASCII. What separates them is that the genuine one's bytes read as
     * Latin-1 are NOT valid UTF-8, so the reversal does not apply.
     */
    public function testGenuineLatin1RangeTextIsDistinguishedFromDoubleEncoding(): void
    {
        $this->assertNull( GeoEncodingRepair::repair( 'são paulo' ) );
        $this->assertSame( 'são paulo', GeoEncodingRepair::repair( 'sÃ£o paulo' ) );
    }

    /**
     * Non-Latin1 scripts are protected structurally rather than by a list.
     *
     * Encoding Latin-1 as UTF-8 can only ever produce codepoints up to U+00FF,
     * so a value containing anything above that cannot have been through the
     * conversion. That is what keeps these safe without enumerating scripts.
     */
    public function testNamesOutsideTheLatin1RangeAreNeverTouched(): void
    {
        foreach ( array( '東京', '北京', 'москва', 'αθήνα', 'תל אביב',
                         'القاهرة', 'ソウル', 'บางกอก', 'İstanbul' ) as $name ) {

            $this->assertNull( GeoEncodingRepair::repair( $name ),
                sprintf( '%s is outside Latin-1 and cannot be double-encoded', $name ) );
        }
    }

    public function testAsciiAndEmptyValuesAreLeftAlone(): void
    {
        foreach ( array( 'london', 'new york', 'san francisco', 'us', 'de',
                         'europe', '', ' ' ) as $value ) {

            $this->assertNull( GeoEncodingRepair::repair( $value ) );
        }
    }

    public function testNonStringsAndInvalidUtf8AreLeftAlone(): void
    {
        // Something else is wrong with such a row, and a repair would be
        // guessing, which is what produced this bug in the first place.
        $this->assertNull( GeoEncodingRepair::repair( null ) );
        $this->assertNull( GeoEncodingRepair::repair( 123 ) );
        $this->assertNull( GeoEncodingRepair::repair( array() ) );
        $this->assertNull( GeoEncodingRepair::repair( "\xFF\xFE invalid" ) );
        $this->assertNull( GeoEncodingRepair::repair( "M\xFCnchen" ) ); // raw latin-1
    }

    /**
     * Running the repair twice must not change anything the second time, because
     * the command is bounded and meant to be re-run until it reports nothing.
     */
    public function testTheRepairIsIdempotent(): void
    {
        $once = GeoEncodingRepair::repair( 'MÃ¼nchen' );

        $this->assertSame( 'München', $once );
        $this->assertNull( GeoEncodingRepair::repair( $once ),
            'a repaired value must not be repaired again' );
    }

    public function testIsDoubleEncodedAgreesWithRepair(): void
    {
        $this->assertTrue( GeoEncodingRepair::isDoubleEncoded( 'MÃ¼nchen' ) );
        $this->assertFalse( GeoEncodingRepair::isDoubleEncoded( 'München' ) );
        $this->assertFalse( GeoEncodingRepair::isDoubleEncoded( 'london' ) );
    }

    /** The v1 migration repairs a location's names as it copies them. */
    public function testTheMigrationRepairsALocationOnTheWayThrough(): void
    {
        $location = \OWA\Module\Base\Classes\Migration\FactMigrator::repairedLocation( array(
            'country'      => 'Germany',
            'country_code' => 'DE',
            'state'        => 'Baden-WÃ¼rttemberg',
            'city'         => 'MÃ¼nchen',
        ) );

        $this->assertSame( 'Baden-Württemberg', $location['state'] );
        $this->assertSame( 'München', $location['city'] );
        $this->assertSame( 'Germany', $location['country'], 'a correct name is left alone' );
        $this->assertSame( array(), \OWA\Module\Base\Classes\Migration\FactMigrator::repairedLocation( array() ),
            'a row with no location stays empty' );
    }

    /**
     * A v1 country code is two letters or nothing. v1 upper-cased its "(not
     * set)" sentinel, which then reached a CHAR(2) column and failed the whole
     * batch under strict mode -- found rehearsing a real 1.x install.
     */
    public function testTheMigrationKeepsOnlyACountryCode(): void
    {
        $code = static function ( $v ) {
            return \OWA\Module\Base\Classes\Migration\FactMigrator::repairedLocation( array( 'country_code' => $v ) )['country_code'];
        };

        $this->assertSame( 'DE', $code( 'DE' ) );
        $this->assertSame( 'DE', $code( 'de' ), 'upper-cased' );
        $this->assertSame( 'DE', $code( ' DE ' ) );

        foreach ( array( '(NOT SET)', '(not set)', 'VATICAN CITY STATE)', 'DEU', 'D', '', null, 'D1' ) as $bad ) {
            $this->assertNull( $code( $bad ), var_export( $bad, true ) );
        }
    }
}
