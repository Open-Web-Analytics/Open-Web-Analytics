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

        /*
         * Only what the Status block reads. The editable settings used to need
         * 'configuration', 'editions' and 'edition' passed through as well --
         * the template built its own controls out of them. It reads the
         * registry now, so the current values and the list of editions come
         * from the declaration rather than from here.
         *
         * 'has_key' stays because whether a key is set is a STATUS, not a
         * control: it is the answer to "why are locations blank", and it is
         * reported above the field rather than inside it.
         */
        foreach ( array( 'db_file', 'db_present', 'db_updated', 'has_key' ) as $key ) {

            $this->body->set( $key, isset( $data[ $key ] ) ? $data[ $key ] : null );
        }

        $this->body->set( 'settings_fieldsets',
            \OWA\Module\Base\Classes\SettingsForm::pageFieldSets( 'maxmind_geoip.optionsGeoip' ) );
    }
}
