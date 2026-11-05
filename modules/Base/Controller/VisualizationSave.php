<?php
namespace OWA\Module\Base\Controller;

/**
 * Create or update one visualization.
 */
/**
 * AdminController, not ReportController -- the same base CustomReportSave uses.
 *
 * A save is a mutation, not a report of a time range, and setRedirectAction()
 * only carries the controller's data through on that base. Extending
 * ReportController ran the save correctly and then rendered an empty document.
 */
class VisualizationSave extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );
        $this->setRequiredCapability( 'edit_reports' );
        $this->setNonceRequired();
    }

    /**
     * How many steps one funnel may have.
     *
     * Ten.
     *
     * This was briefly a structural limit -- each step was a derived table
     * nested inside the next, so the count was the nesting depth of the query.
     * That implementation is gone; the counting is one flat scan now, and
     * another step costs one more CASE column and one more term in an OR. So
     * the cap is no longer about the database.
     *
     * Kept anyway, because it is about the CHART. A funnel drawn with fifty
     * stages is unreadable long before it is expensive, and a limit somebody
     * meets while building is kinder than one they meet while reading.
     */
    const MAX_STEPS = 10;

    public function validate() {

        $this->addValidation( 'name', trim( (string) $this->getParam( 'name' ) ), 'required',
            array( 'errorMsg' => \OWA\Core\CoreAPI::t( 'Name is required.' ) ) );

        /*
         * The step rules, from Classes\FunnelStep, which the preview applies
         * too. Every step is an event: a page is a page_view with a page_path
         * condition. A row of an old kind -- a path, a goal event definition --
         * is refused by name rather than dropped.
         */
        $customColumns = \OWA\Module\Base\Classes\EventPredicate::customColumnsFor(
            (string) $this->getParam( 'propertyId' ) );
        $kept = 0;

        foreach ( $this->rowsFromRequest() as $i => $row ) {

            $number = $i + 1;

            if ( \OWA\Module\Base\Classes\FunnelStep::isBlank( $row ) ) {

                continue;
            }

            $kept++;

            foreach ( \OWA\Module\Base\Classes\FunnelStep::problems( $row, $number, $customColumns ) as $field => $message ) {

                $this->addValidation( $field, '', 'required', array( 'errorMsg' => $message ) );
            }
        }

        // About the list, not one row: keyed `steps`, shown under the steps.
        if ( ! $kept ) {

            $this->addValidation( 'steps', '', 'required',
                array( 'errorMsg' => \OWA\Core\CoreAPI::t( 'A funnel needs at least one step.' ) ) );
        }

        /* A ceiling on the CHART rather than on the query -- see MAX_STEPS. */
        if ( $kept > self::MAX_STEPS ) {

            $this->addValidation( 'steps', '', 'required', array(
                'errorMsg' => sprintf(
                    \OWA\Core\CoreAPI::t( 'A funnel has at most %d steps; this one has %d.' ),
                    self::MAX_STEPS, $kept ),
            ) );
        }

        /*
         * The funnel's filter, which applies to every step. Checked the way a
         * widget's constraints are, so one that cannot resolve is refused
         * here rather than every time the funnel is opened.
         */
        $problem = \OWA\Module\Base\Classes\CustomReports::constraintsProblem(
            (string) $this->getParam( 'constraints' ), \OWA\Core\CoreAPI::t( 'The funnel' ),
            (string) $this->getParam( 'propertyId' ) );

        if ( $problem !== '' ) {

            $this->addValidation( 'constraints', '', 'required', array( 'errorMsg' => $problem ) );
        }
    }

    /**
     * The submitted rows, one per step row of the form, in order.
     *
     * The form posts each step's fields as parallel arrays -- stepName[],
     * stepEvent[], stepCustomEvent[], stepMatch[] -- and its conditions as
     * arrays keyed by the step's position, stepConditionProperty[N][] and its
     * Operator and Value siblings, which the builder renumbers as steps move.
     * stepKind[], stepPath[] and stepGoalEventDefinitionId[] are read too, so a
     * post from a builder before Update079 is refused rather than half read.
     *
     * The event picker's "another event of yours" names the event in the field
     * beside it, as on the goal event definition form.
     *
     * @return array  list of { name, kind, path, goal_event_definition_id, event, custom, match, conditions }
     */
    public function rowsFromRequest() {

        $field = function ( $name ) {

            return (array) $this->getParam( $name );
        };

        $names      = $field( 'stepName' );
        $kinds      = $field( 'stepKind' );
        $paths      = $field( 'stepPath' );
        $goals      = $field( 'stepGoalEventDefinitionId' );
        $events     = $field( 'stepEvent' );
        $customs    = $field( 'stepCustomEvent' );
        $matches    = $field( 'stepMatch' );
        $properties = $field( 'stepConditionProperty' );
        $operators  = $field( 'stepConditionOperator' );
        $values     = $field( 'stepConditionValue' );

        $indexes = array_unique( array_merge( array_keys( $names ), array_keys( $kinds ), array_keys( $paths ),
            array_keys( $goals ), array_keys( $events ), array_keys( $values ) ) );
        sort( $indexes );

        $scalar = static function ( $list, $key ) {

            return isset( $list[ $key ] ) && is_scalar( $list[ $key ] ) ? (string) $list[ $key ] : '';
        };

        $rows = array();

        foreach ( $indexes as $i ) {

            $conditions = array();

            foreach ( (array) ( $values[ $i ] ?? array() ) as $c => $value ) {

                $conditions[] = array(
                    'property' => $scalar( (array) ( $properties[ $i ] ?? array() ), $c ),
                    'operator' => $scalar( (array) ( $operators[ $i ] ?? array() ), $c ),
                    'value'    => is_scalar( $value ) ? (string) $value : '',
                );
            }

            $picked = array_key_exists( $i, $events ) ? $scalar( $events, $i ) : \OWA\Module\Base\Classes\FunnelStep::DEFAULT_EVENT;
            $custom = $picked === \OWA\Module\Base\Entity\GoalEventDefinition::TRIGGER_CUSTOM;

            $rows[] = array(
                'name'                     => $scalar( $names, $i ),
                'kind'                     => $scalar( $kinds, $i ),
                'path'                     => $scalar( $paths, $i ),
                'goal_event_definition_id' => $scalar( $goals, $i ),
                'event'                    => $custom ? trim( $scalar( $customs, $i ) ) : trim( $picked ),
                'custom'                   => $custom,
                'match'                    => $scalar( $matches, $i ),
                'conditions'               => $conditions,
            );
        }

        return $rows;
    }

    /**
     * The steps submitted rows make, as a definition stores them.
     *
     * A blank row is skipped, and so is a row of a kind no longer saved, which
     * validate() refuses. A step with no name is named for its page or its
     * event (FunnelStep::defaultName()): the builder does not ask for a name,
     * and keeps a stored one only while the step it names is unchanged.
     *
     * @param  array $rows  as rowsFromRequest() makes them
     * @return array
     */
    public static function stepsFromForm( array $rows ) {

        $steps = array();

        foreach ( $rows as $row ) {

            $step = is_array( $row )
                ? \OWA\Module\Base\Classes\FunnelStep::fromRow( $row, count( $steps ) + 1 ) : null;

            if ( $step !== null ) {

                $steps[] = $step;
            }
        }

        return $steps;
    }

    function action() {

        $id   = (string) $this->getParam( 'visualizationId' );
        $user = \OWA\Core\CoreAPI::getCurrentUser();

        $report = \OWA\Core\CoreAPI::entityFactory( 'base.custom_report' );

        if ( $id ) {

            $report->load( $id );
        }

        $steps = self::stepsFromForm( $this->rowsFromRequest() );

        $definition = array( 'steps' => $steps );

        $constraints = trim( (string) $this->getParam( 'constraints' ) );

        if ( $constraints !== '' ) {

            $definition['constraints'] = $constraints;
        }

        $report->set( 'name', trim( (string) $this->getParam( 'name' ) ) );
        $report->set( 'report_type',
            \OWA\Module\Base\Entity\CustomReport::TYPE_VISUALIZATION );
        $report->set( 'visualization_type', $this->getParam( 'visualizationType' ) ?: 'funnel' );
        /*
         * ENCODED. The column is a blob holding JSON -- CustomReports::save()
         * json_encodes before writing, and load() decodes on the way back.
         * Handing it an array stores the string "Array", which is what a
         * PHP array casts to, and the visualization then renders nothing.
         */
        $report->set( 'definition', json_encode( $definition ) );
        $report->set( 'last_updated_timestamp', \OWA\Core\CoreAPI::getRequestTimestamp() );
        /*
         * Listed to everybody, or only to its author. DISCOVERABILITY, not
         * access -- the URL already renders for anyone with view_reports. See
         * CustomReports::roster().
         */
        $report->set( 'is_shared', $this->getParam( 'isShared' ) ? 1 : 0 );

        if ( $report->wasPersisted() ) {

            $report->update();

        } else {

            $report->set( 'id', $report->generateId(
                'visualization:' . uniqid( '', true ) ) );
            $report->set( 'user_id', (string) $user->getUserData( 'user_id' ) );
            $report->set( 'creation_timestamp', \OWA\Core\CoreAPI::getRequestTimestamp() );
            $report->create();
        }

        /*
         * Straight to the visualization itself, like a saved report goes to the
         * report. The author's next question is whether it looks right, and a
         * roster cannot answer that.
         */
        $this->set( 'reportId',
            \OWA\Module\Base\Controller\Report::CUSTOM_PREFIX . $report->get( 'id' ) );

        $propertyId = (string) $this->getParam( 'propertyId' );

        if ( $propertyId !== '' ) {

            $this->set( 'propertyId', $propertyId );
        }

        $this->setRedirectAction( 'base.report' );
    }

    /**
     * Send them back to the builder, carrying what they typed.
     *
     * A REDIRECT rather than rendering the form from here.
     *
     * The report builder refuses by constructing CustomReportEdit and returning
     * its doAction(). That works there; here it produced an empty document, and
     * so did setting the subview and view directly -- this controller is an
     * AdminController (which is what makes the success redirect carry its data)
     * and the edit screen is a ReportController that needs its own chrome.
     *
     * Redirecting sidesteps the mismatch entirely and is what the rest of the
     * admin does on a refused save. The submitted values ride along so the form
     * comes back filled in rather than blank -- losing someone's typing is a
     * worse failure than the one being reported.
     */
    function errorAction() {

        $this->set( 'visualizationId', $this->getParam( 'visualizationId' ) );
        $this->set( 'name', $this->getParam( 'name' ) );
        $this->set( 'visualizationType', $this->getParam( 'visualizationType' ) );

        // Shared and the filter come back as they were posted, like the steps.
        $this->set( 'isShared', $this->getParam( 'isShared' ) ? 1 : 0 );
        $this->set( 'constraints', (string) $this->getParam( 'constraints' ) );

        /*
         * The steps as ONE encoded param.
         *
         * A redirect carries a query string, and an array put on one arrives as
         * the literal "Array" -- so the two parallel step arrays cannot ride
         * along as themselves. Encoded together they survive intact, which
         * matters most in exactly the case that gets refused: a funnel someone
         * has typed several steps into.
         */
        /*
         * EVERY row, blank ones and conditions with no value included, and the
         * event as picked: this is the refusal path, so it carries back what
         * somebody typed rather than what will be stored.
         */
        $steps = array();

        foreach ( $this->rowsFromRequest() as $row ) {

            $steps[] = array(
                'name'         => $row['name'],
                'kind'         => \OWA\Module\Base\Classes\FunnelStep::KIND_EVENT,
                'event'        => $row['custom'] ? \OWA\Module\Base\Entity\GoalEventDefinition::TRIGGER_CUSTOM : $row['event'],
                'custom_event' => $row['custom'] ? $row['event'] : '',
                'match'        => \OWA\Module\Base\Classes\FunnelStep::matchOf( $row ),
                'conditions'   => $row['conditions'],
            );
        }

        $this->set( 'submittedSteps', json_encode( $steps ) );

        /*
         * The REASONS, carried the same way the steps are.
         *
         * A redirect keeps nothing the controller set on itself, so every
         * per-field message this save writes was being dropped and the author
         * saw only the chrome's generic "the form data contained one or more
         * errors". For a path that is survivable -- the field is right there
         * and its problem is usually obvious. For a condition it often is not:
         * "a click event does not carry page_title" is the whole of what the
         * author needs, and without it there is nothing on the screen to
         * explain why an ordinary-looking choice was refused.
         *
         * Encoded together, because a redirect turns an array into the literal
         * "Array" -- the same reason the steps travel that way.
         */
        $this->set( 'validationErrors', json_encode( (array) $this->getValidationErrorMsgs() ) );

        $propertyId = (string) $this->getParam( 'propertyId' );

        if ( $propertyId !== '' ) {

            $this->set( 'propertyId', $propertyId );
        }

        $this->set( 'error_code', 3002 );
        $this->setRedirectAction( 'base.visualizationEdit' );
    }
}
