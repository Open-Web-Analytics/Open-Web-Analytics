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
         * The step rules, unchanged from the screen that used to own them.
         * Every one was earned by a bug.
         */
        $names = (array) $this->getParam( 'stepName' );
        $kept  = 0;

        foreach ( (array) $this->getParam( 'stepPath' ) as $i => $path ) {

            $name   = trim( (string) ( $names[ $i ] ?? '' ) );
            $path   = trim( (string) $path );
            $number = $i + 1;

            /* A row someone added and left alone is not a mistake. */
            if ( $name === '' && $path === '' ) {

                continue;
            }

            $kept++;

            $this->addValidation( 'stepName' . $number, $name, 'required',
                array( 'errorMsg' => sprintf( 'Step %s needs a name.', $number ) ) );

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

    function action() {

        $id   = (string) $this->getParam( 'visualizationId' );
        $user = \OWA\Core\CoreAPI::getCurrentUser();

        $report = \OWA\Core\CoreAPI::entityFactory( 'base.custom_report' );

        if ( $id ) {

            $report->load( $id );
        }

        $names = (array) $this->getParam( 'stepName' );
        $steps = array();

        foreach ( (array) $this->getParam( 'stepPath' ) as $i => $path ) {

            $path = trim( (string) $path );

            if ( $path === '' ) {

                continue;
            }

            $steps[] = array(
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
        $steps = array();

        foreach ( (array) $this->getParam( 'stepPath' ) as $i => $path ) {

            $steps[] = array( 'name' => $names[ $i ] ?? '', 'path' => $path );
        }

        $this->set( 'submittedSteps', json_encode( $steps ) );

        $siteId = (string) $this->getParam( 'siteId' );

        if ( $siteId !== '' ) {

            $this->set( 'siteId', $siteId );
        }

        $this->set( 'error_code', 3002 );
        $this->setRedirectAction( 'base.visualizationEdit' );
    }
}
