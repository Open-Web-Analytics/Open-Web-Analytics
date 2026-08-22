<?php

namespace OWA\Module\MaxmindGeoip\View;

class OptionsGeoip extends \OWA\Core\View {

    function render( $data ) {

        // setTemplateFile(), not set_template(): the latter searches the
        // template directory the Template was constructed with, and Core\View
        // constructs it without caller_params, so that directory is always
        // Base's regardless of the module passed. A module shipping its own
        // template has to name the module here or the file is never found --
        // and the failure is silent, because ViewScope raises inside the output
        // buffer and the buffer is discarded.
        $this->body->setTemplateFile( 'maxmind_geoip', 'options_geoip.php' );

        foreach ( array( 'configuration', 'editions', 'edition', 'db_file',
                         'db_present', 'db_updated', 'has_key' ) as $key ) {

            $this->body->set( $key, isset( $data[ $key ] ) ? $data[ $key ] : null );
        }
    }
}
