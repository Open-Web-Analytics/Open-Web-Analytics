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

    public function validate() {

        $this->addValidation( 'name', trim( (string) $this->getParam( 'name' ) ), 'required',
            array( 'errorMsg' => 'A visualization needs a name.' ) );

        /*
         * The step rules. Every one was earned by a bug.
         *
         * A step is either a PAGE or a GOAL EVENT, and the kind decides which
         * of the two fields has to be filled in. Both fields are posted -- the
         * form renders both so it works without JavaScript -- so the kind is
         * what says which one to read, and reading the wrong one would refuse a
         * perfectly complete step.
         */
        $names = (array) $this->getParam( 'stepName' );
        $kinds = (array) $this->getParam( 'stepKind' );
        $goals = (array) $this->getParam( 'stepGoalEventId' );
        $kept  = 0;

        foreach ( (array) $this->getParam( 'stepPath' ) as $i => $path ) {

            $name   = trim( (string) ( $names[ $i ] ?? '' ) );
            $path   = trim( (string) $path );
            $goal   = trim( (string) ( $goals[ $i ] ?? '' ) );
            $number = $i + 1;

            $isGoal = ( (string) ( $kinds[ $i ] ?? 'path' ) ) === 'goal_event';

            /* A row someone added and left alone is not a mistake. */
            if ( $name === '' && ( $isGoal ? $goal === '' : $path === '' ) ) {

                continue;
            }

            $kept++;

            $this->addValidation( 'stepName' . $number, $name, 'required',
                array( 'errorMsg' => sprintf( 'Step %s needs a name.', $number ) ) );

            if ( $isGoal ) {

                $this->addValidation( 'stepGoalEvent' . $number, $goal, 'required',
                    array( 'errorMsg' => sprintf(
                        'Step %s needs a goal event chosen.', $number ) ) );

                /*
                 * And it has to be one a funnel can actually count.
                 *
                 * A goal event may test any tracking property; a funnel step is
                 * matched against the page, because that is what its query
                 * joins. Refusing HERE means the author is told while they can
                 * still choose another -- rather than saving something that
                 * refuses to draw every time it is opened.
                 */
                if ( $goal !== '' ) {

                    $error = $this->goalEventStepError( $goal );

                    if ( $error !== '' ) {

                        $this->addValidation( 'stepGoalEvent' . $number, '', 'required',
                            array( 'errorMsg' => sprintf( 'Step %s: %s', $number, $error ) ) );
                    }
                }

                continue;
            }

            $this->addValidation( 'stepPath' . $number, $path, 'required',
                array( 'errorMsg' => sprintf( 'Step %s needs a path.', $number ) ) );

            /*
             * A path, not a URL. The counting matches on the path alone, so a
             * full web address matches nothing and every stage reports zero.
             * Refused rather than silently trimmed.
             */
            if ( $path !== '' && preg_match( '~^[a-z][a-z0-9+.\-]*://~i', $path ) ) {

                $this->addValidation( 'stepPath' . $number, '', 'required', array(
                    'errorMsg' => sprintf(
                        'Step %s: enter the page PATH, such as /basket -- not a full web address. '
                        . 'Steps are matched on the path alone.', $number ),
                ) );
            }
        }

        if ( ! $kept ) {

            $this->addValidation( 'stepPath1', '', 'required',
                array( 'errorMsg' => 'A funnel needs at least one step.' ) );
        }
    }

    /**
     * Why this goal event cannot be a funnel step, or '' if it can.
     *
     * Asked of the SAME compiler the funnel uses, not of a second list of
     * acceptable properties written here -- two lists is how a screen comes to
     * accept something the report then refuses.
     *
     * @param  string $goalEventId
     * @return string
     */
    private function goalEventStepError( $goalEventId ) {

        $goalEvent = \OWA\Core\CoreAPI::entityFactory( 'base.goal_event' );
        $goalEvent->load( $goalEventId );

        if ( ! $goalEvent->wasPersisted() ) {

            return 'that goal event no longer exists.';
        }

        $predicate = new \OWA\Module\Base\Classes\GoalEventPredicate;

        if ( $predicate->compile( $goalEvent ) !== null ) {

            return '';
        }

        return sprintf(
            '"%s" tests %s. A funnel step is matched against the page -- its URL, its title '
            . 'or its type -- so this one cannot be counted as a stage.',
            (string) $goalEvent->get( 'name' ), $predicate->getError() );
    }

    function action() {

        $id   = (string) $this->getParam( 'visualizationId' );
        $user = \OWA\Core\CoreAPI::getCurrentUser();

        $report = \OWA\Core\CoreAPI::entityFactory( 'base.custom_report' );

        if ( $id ) {

            $report->load( $id );
        }

        $names = (array) $this->getParam( 'stepName' );
        $kinds = (array) $this->getParam( 'stepKind' );
        $goals = (array) $this->getParam( 'stepGoalEventId' );
        $steps = array();

        foreach ( (array) $this->getParam( 'stepPath' ) as $i => $path ) {

            $path   = trim( (string) $path );
            $goal   = trim( (string) ( $goals[ $i ] ?? '' ) );
            $isGoal = ( (string) ( $kinds[ $i ] ?? 'path' ) ) === 'goal_event';

            if ( $isGoal ? $goal === '' : $path === '' ) {

                continue;
            }

            /*
             * ONE of the two, never both.
             *
             * The form posts both fields whatever the kind, because both are
             * rendered so it works with no JavaScript. Storing the unused one
             * as well would leave a step carrying a stale path beside its goal
             * event, and the next reader could not tell which the funnel
             * actually counts. The counting reads goal_event_id first, so a
             * leftover path would be silent rather than wrong -- which is worse
             * to find later, not better.
             */
            $steps[] = $isGoal
                ? array(
                    'name'          => trim( (string) ( $names[ $i ] ?? '' ) ),
                    'goal_event_id' => $goal,
                    'step_number'   => count( $steps ) + 1,
                  )
                : array(
                    'name'        => trim( (string) ( $names[ $i ] ?? '' ) ),
                    'path'        => $path,
                    'step_number' => count( $steps ) + 1,
                  );
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
        $report->set( 'definition', json_encode( array( 'steps' => $steps ) ) );
        $report->set( 'last_updated_timestamp', \OWA\Core\CoreAPI::getRequestTimestamp() );

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

        $siteId = (string) $this->getParam( 'siteId' );

        if ( $siteId !== '' ) {

            $this->set( 'siteId', $siteId );
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
        /*
         * The steps as ONE encoded param.
         *
         * A redirect carries a query string, and an array put on one arrives as
         * the literal "Array" -- so the two parallel step arrays cannot ride
         * along as themselves. Encoded together they survive intact, which
         * matters most in exactly the case that gets refused: a funnel someone
         * has typed several steps into.
         */
        $names = (array) $this->getParam( 'stepName' );
        $goals = (array) $this->getParam( 'stepGoalEventId' );
        $steps = array();

        /*
         * EVERY field, including the one the kind did not use.
         *
         * This is the refusal path, so it is carrying back what somebody typed
         * rather than what will be stored -- a step refused for naming an
         * unusable goal event must come back still showing that goal event, or
         * the message explains a choice the form no longer displays.
         */
        foreach ( (array) $this->getParam( 'stepPath' ) as $i => $path ) {

            $steps[] = array(
                'name'          => $names[ $i ] ?? '',
                'path'          => $path,
                'goal_event_id' => $goals[ $i ] ?? '',
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
         * and its problem is usually obvious. For a goal event it is not: "this
         * one tests medium, which a funnel cannot count against" is the whole
         * of what the author needs, and without it there is nothing on the
         * screen to explain why a perfectly ordinary-looking choice was
         * refused.
         *
         * Encoded together, because a redirect turns an array into the literal
         * "Array" -- the same reason the steps travel that way.
         */
        $this->set( 'validationErrors', json_encode( (array) $this->getValidationErrorMsgs() ) );

        $siteId = (string) $this->getParam( 'siteId' );

        if ( $siteId !== '' ) {

            $this->set( 'siteId', $siteId );
        }

        $this->set( 'error_code', 3002 );
        $this->setRedirectAction( 'base.visualizationEdit' );
    }
}
