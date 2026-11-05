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
                $this->setRedirectAction( 'base.customReports' );

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

        /*
         * The messages from a refused save, which arrive encoded for the same
         * reason the steps do. Set here rather than left to the framework: the
         * framework fills validation_errors for the controller that refused,
         * and that controller redirected to this one.
         */
        $this->set( 'validation_errors', $this->submittedErrors() );

        $type = $this->resolveType( $report );

        $this->set( 'visualizationId', $id );
        $this->set( 'visualization', $this->getParam( 'name' ) !== null
            ? array(
                'id'                 => $id,
                'name'               => $this->getParam( 'name' ),
                'visualization_type' => $type,
                // Carried back on a refused save, like the name is. The stored
                // row already has it on the other branch.
                'is_shared'          => $this->getParam( 'isShared' ) ? 1 : 0,
              )
            : (array) $report );
        $this->set( 'visualizationType', $type );
        $this->set( 'visualizationTypeLabel',
            \OWA\Module\Base\Classes\CustomReports::VISUALIZATION_TYPES[ $type ] ?? $type );
        $this->set( 'visualizationTypeHint',
            \OWA\Module\Base\Classes\CustomReports::VISUALIZATION_TYPE_HINTS[ $type ] ?? '' );
        $this->set( 'visualizationTypeIcon',
            \OWA\Module\Base\Classes\CustomReports::VISUALIZATION_TYPE_ICONS[ $type ] ?? '' );

        $propertyId = (string) $this->getParam( 'propertyId' );

        /*
         * The Property, so the FORM can carry it.
         *
         * makeLink() does not add state unless it is asked to, so a form action
         * built from it posts to a URL with no scope on it -- and the save then
         * redirects to the funnel without one. Everything drew: the right
         * stages, the right names, and `r.site_id = ''` behind them, so every
         * stage reported zero visitors and looked like a site nobody had used.
         *
         * A hidden field rather than makeLink(..., true), because that is what
         * the custom report builder does and one pattern is easier to keep
         * right than two.
         */
        $this->set( 'propertyId', $propertyId );

        /*
         * What a step can match: the events and the vocabulary the goal event
         * definition form offers (GoalEventDefinitionEdit) -- OWA's events a
         * definition may be triggered by, this Property's own, and each
         * event's properties -- and beside every event's properties the
         * Property's custom dimensions, which a funnel can test because it
         * counts over the cube that carries them.
         */
        $customDimensions = \OWA\Module\Base\Classes\DimensionRegistry::forProperty( $propertyId )->custom();

        $this->set( 'eventNames', \OWA\Module\Base\Classes\TrackingEventHelpers::goalTriggerEventNames() );
        $this->set( 'customEventNames', GoalEventDefinitionEdit::customEventNames( $propertyId ) );
        $this->set( 'conditionVocabulary', self::vocabulary( $customDimensions ) );
        $this->set( 'steps', self::formSteps( $submitted ?: $stored, $customDimensions ) );
        // The row Add a step copies.
        $this->set( 'blankStep', self::formSteps( array( array() ), $customDimensions )[0] );

        /*
         * The step cap, read from the controller that enforces it so the form
         * cannot offer a step the save then refuses.
         */
        $this->set( 'maxSteps', \OWA\Module\Base\Controller\VisualizationSave::MAX_STEPS );

        /*
         * The funnel's filter: as posted when a save was refused, else as
         * stored. What it may filter on is what a widget's filter may.
         */
        $this->set( 'constraints', $this->getParam( 'name' ) !== null
            ? (string) $this->getParam( 'constraints' )
            : (string) ( $definition['constraints'] ?? '' ) );
        $this->set( 'dimensionChoices', CustomReportEdit::dimensionChoices( $propertyId ) );
    }

    /**
     * Every event's condition properties, for the builder's pickers, with the
     * Property's custom dimensions added to each list.
     *
     * @param  array $customDimensions  DimensionRegistry::custom()
     * @return array  event => list of { name, label, description, carried }
     */
    public static function vocabulary( array $customDimensions ) {

        $vocabulary = GoalEventDefinitionEdit::vocabulary();

        foreach ( $vocabulary as $event => $properties ) {

            $vocabulary[ $event ] = array_merge( $properties, self::customProperties( $customDimensions ) );
        }

        return $vocabulary;
    }

    /** The custom dimensions as picker entries. */
    private static function customProperties( array $customDimensions ) {

        $out = array();

        foreach ( $customDimensions as $dimension ) {

            $out[] = array( 'name' => (string) $dimension['column'], 'label' => (string) $dimension['label'],
                'description' => (string) $dimension['description'], 'carried' => true );
        }

        return $out;
    }

    /**
     * The steps as the form draws them: the event as picked, all or any, the
     * conditions, and the properties each condition's picker offers.
     *
     * A new funnel's one step, and any stored step that is not an event step,
     * draw as the default: a page_view with an empty page_path condition, the
     * common case, ready to type a path into.
     *
     * A stored step on a site's own event is picked by name; a refused save
     * carries "another event of yours" as picked, with the name typed beside it.
     *
     * @param  array $steps             stored, or carried back by a refused save
     * @param  array $customDimensions  DimensionRegistry::custom()
     * @return array  list of { name, picked, custom_event, match, conditions, properties }
     */
    public static function formSteps( array $steps, array $customDimensions ) {

        $default = \OWA\Module\Base\Classes\FunnelStep::DEFAULT_EVENT;
        $custom  = \OWA\Module\Base\Entity\GoalEventDefinition::TRIGGER_CUSTOM;
        $columns = array_column( $customDimensions, 'label', 'column' );
        $out     = array();

        foreach ( $steps ?: array( array() ) as $step ) {

            $step = (array) $step;

            $isEvent = ( $step['kind'] ?? '' ) === \OWA\Module\Base\Classes\FunnelStep::KIND_EVENT;
            $picked  = $isEvent ? (string) ( $step['event'] ?? '' ) : $default;

            $conditions = array();

            foreach ( $isEvent ? (array) ( $step['conditions'] ?? array() ) : array() as $condition ) {

                if ( is_array( $condition ) ) {

                    $conditions[] = array(
                        'property' => (string) ( $condition['property'] ?? '' ),
                        'operator' => (string) ( $condition['operator'] ?? '' ),
                        'value'    => (string) ( $condition['value'] ?? '' ),
                    );
                }
            }

            if ( ! $conditions ) {

                $conditions[] = array( 'property' => $picked === $default
                    ? \OWA\Module\Base\Classes\FunnelStep::DEFAULT_PROPERTY : '',
                    'operator' => \OWA\Module\Base\Entity\GoalEventDefinition::MATCH_EXACT, 'value' => '' );
            }

            $typed = $picked === $custom ? (string) ( $step['custom_event'] ?? '' ) : '';
            $event = $picked === $custom ? ( $typed !== '' ? $typed : $custom ) : $picked;

            // A custom dimension is not one of the event's properties, so it is not passed as "in use".
            $inUse = array();

            foreach ( $conditions as $condition ) {

                if ( ! isset( $columns[ $condition['property'] ] ) ) {

                    $inUse[] = array( 'condition_property' => $condition['property'] );
                }
            }

            $out[] = array(
                'name'         => $isEvent ? (string) ( $step['name'] ?? '' ) : '',
                'picked'       => $picked,
                'custom_event' => $typed,
                'match'        => \OWA\Module\Base\Classes\FunnelStep::matchOf( $step ),
                'conditions'   => $conditions,
                'properties'   => array_merge( GoalEventDefinitionEdit::conditionProperties( $event, $inUse ),
                    self::customProperties( $customDimensions ) ),
            );
        }

        return $out;
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
     * The per-field messages from a refused save.
     *
     * @return array
     */
    private function submittedErrors() {

        $encoded = (string) $this->getParam( 'validationErrors' );

        if ( $encoded === '' ) {

            return array();
        }

        $errors = json_decode( $encoded, true );

        return is_array( $errors ) ? $errors : array();
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
        $this->set( 'title', $this->get( 'visualizationId' ) ? 'Funnel' : 'New funnel' );

        /* A form is not a report of a time range. */
        $this->hideTimeControls();
        $this->hideSitesFilter();

        // The name field, Shared and Save are the screen's header.
        $this->hideReportHead();
    }
}
