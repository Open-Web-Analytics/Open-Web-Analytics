<?php

require_once __DIR__ . '/bootstrap_owa.php';

use PHPUnit\Framework\TestCase;
use OWA\Module\Base\Classes\Sanitize;
use OWA\Module\Base\Classes\TrackingEventHelpers as Helpers;

/**
 * A property declared as JSON is re-encoded or rejected, never coerced.
 *
 * json_decode() answers null for input that is not JSON at all, and
 * json_encode(null) is the four-character string "null" -- so malformed input
 * was stored as if it were data, and a reader taking it at face value gets a
 * string where it expected a structure.
 */
final class JsonPropertyValidationTest extends TestCase
{
    /** @dataProvider valid */
    public function testValidJsonSurvives( string $in, string $expected ): void
    {
        $this->assertSame( $expected, Helpers::setDataType( $in, 'json' ) );
    }

    public static function valid(): array
    {
        return array(
            'object'  => array( '{"a":1}', '{"a":1}' ),
            'array'   => array( '[1,2]',   '[1,2]' ),
            'string'  => array( '"str"',   '"str"' ),
            // The literal "null" IS valid JSON, which is why the rejection test
            // cannot simply be "did this decode to null".
            'null'    => array( 'null',    'null' ),
            // Valid JSON, and falsy in PHP. A truthiness guard rejected these.
            'zero'    => array( '0',       '0' ),
            'false'   => array( 'false',   'false' ),
        );
    }

    /** @dataProvider malformed */
    public function testMalformedJsonIsRejectedRatherThanStored( string $in ): void
    {
        $out = Helpers::setDataType( $in, 'json' );

        $this->assertNotSame( 'null', $out,
            'malformed JSON was coerced to the string "null" and stored as data' );
        $this->assertSame( '', $out );
    }

    public static function malformed(): array
    {
        return array(
            'prose'      => array( 'not json at all' ),
            'truncated'  => array( '{"a":' ),
            'bare word'  => array( 'undefined' ),
            'html'       => array( '<script>alert(1)</script>' ),
        );
    }

    /** Absence stays absence. */
    public function testAbsenceIsUntouched(): void
    {
        $this->assertNull( Sanitize::cleanJson( '' ) );
        $this->assertNull( Sanitize::cleanJson( null ) );
    }

    /*
     * testTheAttributionStackIsTheJsonProperty WAS HERE. `attribs` was the
     * only json-typed tracking property, and it is gone: it carried the
     * client's campaign attribution stack, which only the v1 SessionHandlers
     * ever read, and the v2 tracker no longer computes or sends one.
     *
     * The json data type itself is still exercised by the cases around this,
     * which is why they stayed.
     */
}
