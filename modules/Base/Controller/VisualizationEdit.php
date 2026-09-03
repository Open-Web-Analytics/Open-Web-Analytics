<?php
namespace OWA\Module\Base\Controller;

/**
 * Build or edit one visualization.
 *
 * The KIND is decided before this screen opens -- in the modal on the roster,
 * the way a widget's type is chosen before its settings are -- because the kind
 * decides what this form asks for. It arrives on the URL and is shown here as a
 * fact rather than asked again: two places to answer one question is a way for
 * the two to disagree.
 *
 * It is fixed once saved. The definition a kind produces means nothing to
 * another kind, so re-typing a funnel would leave a visualization holding steps
 * that whatever computes it does not read. Building the other kind is the way
 * to get the other kind.
 *
 * Only the funnel exists today, which is why the kind is named at all: a single
 * hardcoded one would not need naming, and the map means the second costs a row
 * in VISUALIZATION_TYPES rather than a dispatcher change.
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

        $type = $this->resolveType( $report );

        $this->set( 'visualizationId', $id );
        $this->set( 'visualization', $this->getParam( 'name' ) !== null
            ? array(
                'id'                 => $id,
                'name'               => $this->getParam( 'name' ),
                'visualization_type' => $type,
              )
            : (array) $report );
        $this->set( 'steps', $submitted ?: $stored );
        $this->set( 'visualizationType', $type );
        $this->set( 'visualizationTypeLabel',
            \OWA\Module\Base\Classes\CustomReports::VISUALIZATION_TYPES[ $type ] ?? $type );
        $this->set( 'visualizationTypeHint',
            \OWA\Module\Base\Classes\CustomReports::VISUALIZATION_TYPE_HINTS[ $type ] ?? '' );
        $this->set( 'visualizationTypeIcon',
            \OWA\Module\Base\Classes\CustomReports::VISUALIZATION_TYPE_ICONS[ $type ] ?? '' );

    }

    /**
     * Which kind this is.
     *
     * A STORED kind wins over anything on the URL: the definition was written
     * by that kind and is only meaningful to it, so a URL naming another would
     * hand the wrong builder a funnel's steps. For a new one the URL is the
     * answer the modal gave.
     *
     * Checked against the known kinds rather than trusted, because it decides
     * which controller computes the result -- an unknown name is not an error
     * worth a page for, it is simply not a kind, so it falls back to the first.
     *
     * @param  array|null $report
     * @return string
     */
    private function resolveType( $report ) {

        $types = \OWA\Module\Base\Classes\CustomReports::VISUALIZATION_TYPES;

        $stored = $report ? (string) ( $report['visualization_type'] ?? '' ) : '';

        if ( $stored !== '' && isset( $types[ $stored ] ) ) {

            return $stored;
        }

        $asked = (string) $this->getParam( 'visualizationType' );

        if ( $asked !== '' && isset( $types[ $asked ] ) ) {

            return $asked;
        }

        return (string) key( $types );
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
