<?php
namespace OWA\Module\MaxmindGeoip\Classes;

/**
 * MaxMind's GeoIP2 City web service, over HTTPS with Guzzle, which Base
 * already requires.
 *
 * The answer is the record the GeoIP2 database reader returns, as an array,
 * so Maxmind::mapCityRecord() reads both.
 */
class WebServiceClient {

    const ENDPOINT = 'https://geoip.maxmind.com/geoip/v2.1/city/';

    /** @var string */
    private $account_id;

    /** @var string */
    private $license_key;

    /** @var \GuzzleHttp\Client */
    private $http;

    /**
     * @param string        $account_id  the MaxMind account id
     * @param string        $license_key
     * @param callable|null $handler     a Guzzle handler, for tests
     */
    public function __construct( $account_id, $license_key, $handler = null ) {

        $this->account_id  = (string) $account_id;
        $this->license_key = (string) $license_key;

        $options = [ 'timeout' => 3.0, 'connect_timeout' => 2.0 ];

        if ( $handler ) {

            $options['handler'] = \GuzzleHttp\HandlerStack::create( $handler );
        }

        $this->http = new \GuzzleHttp\Client( $options );
    }

    /**
     * The city record for an address.
     *
     * Throws for a refused key, an address the service has no record of, a
     * timeout, or an answer that is not a JSON object; the caller treats any
     * of these as no location.
     *
     * @param  string $ip
     * @return array<string, mixed>
     */
    public function city( $ip ) {

        $response = $this->http->get( self::ENDPOINT . rawurlencode( trim( (string) $ip ) ), [
            'auth'    => [ $this->account_id, $this->license_key ],
            'headers' => [ 'Accept' => 'application/json' ],
        ] );

        $record = json_decode( (string) $response->getBody(), true );

        if ( ! is_array( $record ) ) {

            throw new \RuntimeException( 'The MaxMind web service answer is not a JSON object.' );
        }

        return $record;
    }
}
