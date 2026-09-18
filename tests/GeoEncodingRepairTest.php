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


    /*
     * ---------------------------------------------------------------------
     * The command's planning step.
     * ---------------------------------------------------------------------
     */

    /** planRepairs() is protected; it takes rows and returns intentions. */
    private function plan( array $rows ): array
    {
        $method = new ReflectionMethod(
            \OWA\Module\Base\Controller\RepairGeoEncodingCli::class, 'planRepairs' );
        $method->setAccessible( true );

        return $method->invoke(
            ( new ReflectionClass( \OWA\Module\Base\Controller\RepairGeoEncodingCli::class ) )
                ->newInstanceWithoutConstructor(),
            $rows );
    }

    public function testThePlanNamesEveryDoubleEncodedValueAndNothingElse(): void
    {
        $plan = $this->plan( array(
            array( 'id' => '1', 'country' => 'germany', 'state' => 'bayern',    'city' => 'mÃ¼nchen' ),
            array( 'id' => '2', 'country' => 'brazil',  'state' => 'são paulo', 'city' => 'são paulo' ),
            array( 'id' => '3', 'country' => 'russia',  'state' => 'москва',    'city' => 'москва' ),
            array( 'id' => '4', 'country' => 'uk',      'state' => 'england',   'city' => 'london' ),
        ) );

        $this->assertCount( 1, $plan, 'only the double-encoded value should be planned' );
        $this->assertSame( '1', $plan[0]['id'] );
        $this->assertSame( 'city', $plan[0]['column'] );
        $this->assertSame( 'mÃ¼nchen', $plan[0]['from'] );
        $this->assertSame( 'münchen', $plan[0]['to'] );
    }

    public function testThePlanCoversEveryNameColumnOnARow(): void
    {
        $plan = $this->plan( array(
            array( 'id' => '9', 'country' => 'Ã¶sterreich', 'state' => 'kÃ¤rnten', 'city' => 'kÃ¶ln' ),
        ) );

        $this->assertCount( 3, $plan );
        $this->assertSame( array( 'country', 'state', 'city' ),
            array_column( $plan, 'column' ) );
        $this->assertSame( array( 'österreich', 'kärnten', 'köln' ),
            array_column( $plan, 'to' ) );
    }

    public function testAnEmptyOrAbsentColumnIsSkippedRatherThanFatal(): void
    {
        // A row read back with a null column, or a column the select did not
        // return, must not take the scan down with it.
        $plan = $this->plan( array(
            array( 'id' => '5', 'city' => 'mÃ¼nchen' ),
            array( 'id' => '6', 'country' => null, 'state' => '', 'city' => 'kÃ¶ln' ),
        ) );

        $this->assertCount( 2, $plan );
        $this->assertSame( array( 'münchen', 'köln' ), array_column( $plan, 'to' ) );
    }

    public function testNoRowsPlansNothing(): void
    {
        $this->assertSame( array(), $this->plan( array() ) );
    }
}
