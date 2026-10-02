<?php

namespace OWA\Module\Sqs\View;

class OptionsSqs extends \OWA\Core\View {

    function render( $data ) {

        // setTemplateFile(), naming the module: see MaxmindGeoip\View\OptionsGeoip.
        $this->body->setTemplateFile( 'sqs', 'options_sqs.php' );

        foreach ( array( 'queue_name', 'dlq_name', 'credential_source', 'region', 'provisioned', 'in_use' ) as $key ) {

            $this->body->set( $key, $data[ $key ] ?? null );
        }

        $this->body->set( 'settings_fieldsets',
            \OWA\Module\Base\Classes\SettingsForm::pageFieldSets( 'sqs.optionsSqs' ) );
    }
}
