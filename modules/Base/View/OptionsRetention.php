<?php
namespace OWA\Module\Base\View;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

class OptionsRetention extends \OWA\Core\View\AdminPage {

    function render( $data ) {

        $this->body->set_template( 'options_retention.php' );
        $this->body->set( 'settings_fieldsets',
            \OWA\Module\Base\Classes\SettingsForm::pageFieldSets( 'base.optionsRetention' ) );
    }
}

?>
