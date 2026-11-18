<?php
namespace OWA\Module\Base\Controller;

class Updates extends \OWA\Core\Controller {

    // DELIBERATELY has no required capability. Core\Controller::updateAction()
    // redirects here (setRedirectAction('base.updates')) whenever an admin
    // request is intercepted because the schema is out of date -- and that
    // interception runs BEFORE the capability check, for a request that may not
    // be authenticated yet. Gating this controller would put a login wall in
    // front of the very page the interception exists to display.
    //
    // It only lists which modules have pending updates. base.updatesApply, the
    // action that actually mutates the schema, is gated instead.

    public function action() {

        $modules = \OWA\Core\CoreAPI::getModulesNeedingUpdates();

        // Nothing pending: an empty list with an Apply button is a dead end, so
        // go where index.php goes instead. A bookmark, or the page left open in
        // a tab after the updates were applied, lands here.
        if ( empty( $modules ) ) {

            $this->setRedirectAction( \OWA\Core\CoreAPI::getSetting( 'base', 'start_page' ) );

            return;
        }

        // The signed-out wrapper: this page may be the first anything renders,
        // before anyone has signed in.
        $this->setView( 'base.updates', [
            'template' => 'updates.php',
            'wrapper'  => 'wrapper_public.php',
            'vars'     => [ 'headline' => \OWA\Core\CoreAPI::t( 'Your database needs to be upgraded...' ), 'modules' ],
        ] );
        $this->set( 'modules', $modules );
    }
}
