<?php

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap_owa.php';

/**
 * German umlauts in geolocation names. Issue #742.
 *
 * The reader converted every name it read from MaxMind from ISO-8859-1 to UTF-8,
 * on the stated grounds that "MaxMind name fields are Latin-1". They are not: the
 * MaxMind DB format spec defines its string type as "a variable length byte
 * sequence that contains valid utf8", and the module reads .mmdb through
 * MaxMind\Db\Reader exclusively. So names were encoded a second time and
 * "München" was stored as "MÃ¼nchen".
 */
final class GeoNameEncodingTest extends TestCase
{
    /*
     * ---------------------------------------------------------------------
     * The reader itself.
     * ---------------------------------------------------------------------
     */

    /**
     * The source no longer converts, and cannot regress to converting.
     *
     * Read out of the file because mapCityRecord() is private and calling it
     * needs a MaxMind record, which needs the reader package and a database
     * file. What has to stay true is narrow enough to assert directly: no
     * Latin-1 conversion is applied to a name on its way in.
     */
    public function testTheGeolocationReaderDoesNotConvertNames(): void
    {
        $src = file_get_contents(
            dirname( __DIR__ ) . '/modules/MaxmindGeoip/Classes/Maxmind.php' );

        $this->assertNotEmpty( $src, 'the reader source has to be readable for this to mean anything' );

        $this->assertStringNotContainsString( 'latin1ToUtf8', $src,
            'the Latin-1 conversion is back; MaxMind names are UTF-8 and this double-encodes them' );

        // The helper is gone, so no call site can quietly reintroduce it, and
        // nothing else in the reader should be converting a name either.
        $this->assertDoesNotMatchRegularExpression(
            "/mb_convert_encoding\s*\(\s*\\\$?\w+\s*,\s*'UTF-8'\s*,\s*'ISO-8859-1'/", $src );
        $this->assertDoesNotMatchRegularExpression(
            "/iconv\s*\(\s*'ISO-8859-1'/", $src );
    }

    /**
     * The names really are read straight through.
     *
     * The assertion above says what is absent. This one says the six fields that
     * carry a name are assigned from the record with nothing but trim and
     * strtolower in the way, which is what "read straight through" means.
     */
    public function testEveryNameFieldIsAssignedWithoutConversion(): void
    {
        $src = file_get_contents(
            dirname( __DIR__ ) . '/modules/MaxmindGeoip/Classes/Maxmind.php' );

        foreach ( array( 'city', 'continent', 'continent_code',
                         'state', 'state_code', 'country' ) as $field ) {

            $this->assertMatchesRegularExpression(
                "/\\\$location_map\['" . preg_quote( $field, '/' )
                    . "'\]\s*=\s*strtolower\(\s*trim\(/",
                $src,
                sprintf( '%s is no longer assigned directly from the record', $field )
            );
        }
    }
}
