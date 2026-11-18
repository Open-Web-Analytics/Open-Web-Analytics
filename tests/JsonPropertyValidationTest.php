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
        return [
            'object'  => [ '{"a":1}', '{"a":1}' ],
            'array'   => [ '[1,2]',   '[1,2]' ],
            'string'  => [ '"str"',   '"str"' ],
            // The literal "null" IS valid JSON, which is why the rejection test
            // cannot simply be "did this decode to null".
            'null'    => [ 'null',    'null' ],
            // Valid JSON, and falsy in PHP. A truthiness guard rejected these.
            'zero'    => [ '0',       '0' ],
            'false'   => [ 'false',   'false' ],
        ];
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
        return [
            'prose'      => [ 'not json at all' ],
            'truncated'  => [ '{"a":' ],
            'bare word'  => [ 'undefined' ],
            'html'       => [ '<script>alert(1)</script>' ],
        ];
    }

    /** Absence stays absence. */
    public function testAbsenceIsUntouched(): void
    {
        $this->assertNull( Sanitize::cleanJson( '' ) );
        $this->assertNull( Sanitize::cleanJson( null ) );
        $this->assertNull( Sanitize::cleanJson( [] ) );
    }

    /**
     * The tracker sends line items as bracketed keys (o_items[0][item_id]=…),
     * which PHP parses into an array, not JSON text. json_decode() threw a
     * TypeError on it, so a purchase or refund with items was never stored.
     */
    public function testLineItemsSentAsBracketedKeysAreEncoded(): void
    {
        // What PHP makes of o_items[0][item_id]=SKU-1&o_items[0][price]=19.99&
        // o_items[0][quantity]=2&o_items[1][item_name]=Blue mug
        $parsed = [
            0 => [ 'item_id' => 'SKU-1', 'price' => '19.99', 'quantity' => '2' ],
            1 => [ 'item_name' => 'Blue mug' ],
        ];

        $out = Helpers::setDataType( $parsed, 'json' );

        $this->assertSame( $parsed, json_decode( $out, true ) );
        $this->assertStringStartsWith( '[', $out, 'items must encode as a JSON list' );
    }

    /** Items with a gap in their indexes still encode as a list. */
    public function testIntegerKeysWithAGapEncodeAsAList(): void
    {
        $out = Sanitize::cleanJson( [ 1 => [ 'item_id' => 'A' ], 3 => [ 'item_id' => 'B' ] ] );

        $this->assertSame( [ [ 'item_id' => 'A' ], [ 'item_id' => 'B' ] ], json_decode( $out, true ) );
    }

    /** A value in the array is tracking text: control characters go, invalid UTF-8 is read. */
    public function testArrayValuesAreCleanedAsTrackingText(): void
    {
        $out = Sanitize::cleanJson( [ [ 'item_name' => "Mug\x01", 'item_brand' => "Caf\xE9" ] ] );

        $this->assertSame( [ [ 'item_name' => 'Mug', 'item_brand' => 'Café' ] ], json_decode( $out, true ) );
    }
}
