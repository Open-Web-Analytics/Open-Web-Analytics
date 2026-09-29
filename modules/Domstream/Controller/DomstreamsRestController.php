<?php
namespace OWA\Module\Domstream\Controller;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * GET domstreams: one recording, for the player.
 *
 * Its chunks' samples in seq order, as one list, with the viewport of the
 * first chunk. The report builds the list of recordings itself; this serves
 * only playback, from the overlay session on the recorded page.
 */
class DomstreamsRestController extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );
        $this->setRequiredCapability( 'view_reports' );
    }

    function validate() {

        $this->addValidation( 'siteId', $this->getParam( 'siteId' ), 'required', array( 'stopOnError' => true ) );
        $this->addValidation( 'recording_id', $this->getParam( 'recording_id' ), 'required', array( 'stopOnError' => true ) );
    }

    function action() {

        $this->set( 'response', self::recording(
            (string) $this->getParam( 'siteId' ), (string) $this->getParam( 'recording_id' ) ) );
    }

    /**
     * @param  string $site_id
     * @param  string $recording_id
     * @return array  recording_id, viewport_w, viewport_h, samples
     */
    public static function recording( $site_id, $recording_id ) {

        $out = array(
            'recording_id' => $recording_id,
            'viewport_w'   => null,
            'viewport_h'   => null,
            'samples'      => array(),
        );

        if ( ! preg_match( '/^[1-9][0-9]{0,18}$/', $recording_id ) ) {

            return $out;
        }

        $chunk   = \OWA\Core\CoreAPI::entityFactory( 'domstream.domstream_chunk' );
        $payload = \OWA\Core\CoreAPI::entityFactory( 'domstream.domstream_payload' );

        /*
         * The chunk table's composite index finds the recording; the join
         * repeats yyyymmdd so each payload lookup prunes to its partition.
         */
        $rows = (array) \OWA\Core\CoreAPI::dbSingleton()->get_results( sprintf(
            'SELECT c.seq, c.viewport_w, c.viewport_h, p.payload FROM %s c'
            . ' JOIN %s p ON p.id = c.id AND p.yyyymmdd = c.yyyymmdd'
            . ' WHERE c.site_id = ? AND c.recording_id = ? ORDER BY c.seq ASC',
            $chunk->getTableName(), $payload->getTableName() ),
            array( $site_id, $recording_id ) );

        foreach ( $rows as $row ) {

            $row = (array) $row;

            if ( $out['viewport_w'] === null ) {

                $out['viewport_w'] = $row['viewport_w'] === null ? null : (int) $row['viewport_w'];
                $out['viewport_h'] = $row['viewport_h'] === null ? null : (int) $row['viewport_h'];
            }

            $json    = @gzdecode( (string) $row['payload'] );
            $samples = $json === false ? null : json_decode( $json, true );

            if ( is_array( $samples ) ) {

                foreach ( $samples as $sample ) {

                    $out['samples'][] = $sample;
                }
            }
        }

        return $out;
    }

    function success() {

        http_response_code( 201 );
        $this->setView( 'domstream.domstreamsRest' );
    }

    function errorAction() {

        http_response_code( 422 );
        $this->setView( 'domstream.domstreamsRest' );
    }
}

?>
