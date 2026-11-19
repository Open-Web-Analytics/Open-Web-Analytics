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
     * A record as MaxMind\Db\Reader returns it, UTF-8 throughout, mapped by
     * the reader. mapCityRecord() is private and takes the reader's array, so
     * no reader package or database file is needed.
     *
     * @return array<string, string|null>
     */
    private function mapped(): array
    {
        $record = [
            'city'         => [ 'names' => [ 'en' => ' München ' ] ],
            'continent'    => [ 'code' => 'EU', 'names' => [ 'en' => 'Europa Ünion' ] ],
            'subdivisions' => [ [ 'iso_code' => 'BW', 'names' => [ 'en' => 'Baden-Württemberg' ] ] ],
            'country'      => [ 'iso_code' => 'de', 'names' => [ 'en' => 'Österreich' ] ],
        ];

        $reader = ( new ReflectionClass( \OWA\Module\MaxmindGeoip\Classes\Maxmind::class ) )->newInstanceWithoutConstructor();
        $map    = new ReflectionMethod( $reader, 'mapCityRecord' );

        return $map->invoke( $reader, $record, [], 'en' );
    }

    /**
     * The names are read straight through: each field that carries a name
     * holds the record's UTF-8, trimmed and lowercased, and not converted
     * from Latin-1 a second time ("München" stored as "MÃ¼nchen").
     */
    public function testTheGeolocationReaderDoesNotConvertNames(): void
    {
        $map = $this->mapped();

        $this->assertSame( 'münchen', $map['city'] );
        $this->assertSame( 'baden-württemberg', $map['state'] );
        $this->assertSame( 'Österreich', $map['country'] );
    }

    /** Every field that carries a name is assigned from the record. */
    public function testEveryNameFieldIsAssignedWithoutConversion(): void
    {
        $this->assertSame( [
            'city'           => 'münchen',
            'continent'      => 'eu',
            'continent_code' => 'europa Ünion',
            'state'          => 'baden-württemberg',
            'state_code'     => 'bw',
            'country'        => 'Österreich',
            'country_code'   => 'DE',
        ], $this->mapped() );
    }
}
