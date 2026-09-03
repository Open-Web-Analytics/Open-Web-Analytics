<?php
namespace OWA\Module\Base\Controller;

/**
 * Build or edit one visualization.
 *
 * The type is chosen first, the way a widget's type is chosen when building a
 * custom report -- because it decides what the rest of the form asks for. Only
 * the funnel exists today, which is why the choice is offered at all: a single
 * hardcoded kind would not need naming.
 */
class VisualizationEdit extends \OWA\Core\ReportController {

    function __construct( $params ) {

        parent::__construct( $params );
        $this->setRequiredCapability( 'edit_reports' );
    }

    function action() {

        $id     = (string) $this->getParam( 'visualizationId' );
        $report = $id ? \OWA\Module\Base\Classes\CustomReports::load( $id ) : null;

        if ( $report ) {

            $user = \OWA\Core\CoreAPI::getCurrentUser();

            /*
             * Same rule as a custom report: an author edits their own, and
             * someone who administers users edits anyone's.
             */
            $may = \OWA\Module\Base\Classes\CustomReports::mayEdit(
                $report,
                (string) $user->getUserData( 'user_id' ),
                (bool) $user->isCapable( 'edit_users' )
            );

            if ( ! $may ) {

                $this->setStatusCode( 2513 );
                $this->setRedirectAction( 'base.visualizations' );

                return;
            }
        }

        $definition = $report ? (array) $report['definition'] : array();

        $stored = isset( $definition['steps'] ) ? (array) $definition['steps'] : array();

        /*
         * A refused save redirects back here carrying what was typed, so the
         * form comes back filled in. Those values WIN over the stored ones --
         * showing what is saved instead of what was just typed would look like
         * the edit was silently discarded.
         */
        $submitted = $this->submittedSteps();

        $this->set( 'visualizationId', $id );
        $this->set( 'visualization', $this->getParam( 'name' ) !== null
            ? array(
                'id'                 => $id,
                'name'               => $this->getParam( 'name' ),
                'visualization_type' => $this->getParam( 'visualizationType' ),
              )
            : (array) $report );
        $this->set( 'steps', $submitted ?: $stored );
        $this->set( 'visualizationTypes',
            \OWA\Module\Base\Classes\CustomReports::VISUALIZATION_TYPES );
    }

    /**
     * The steps as submitted, when arriving here after a refused save.
     *
     * @return array
     */
    private function submittedSteps() {

        /*
         * Encoded as one param by the save, because a redirect's query string
         * turns an array into the literal "Array".
         */
        $encoded = (string) $this->getParam( 'submittedSteps' );

        if ( $encoded === '' ) {

            return array();
        }

        $steps = json_decode( $encoded, true );

        return is_array( $steps ) ? $steps : array();
    }

    function success() {

        $this->setSubview( 'base.visualizationEdit' );
        $this->setView( 'base.report' );
        $this->set( 'title', $this->get( 'visualizationId' ) ? 'Visualization' : 'New Visualization' );

        /* A form is not a report of a time range. */
        $this->hideTimeControls();
        $this->hideSitesFilter();
    }
}
