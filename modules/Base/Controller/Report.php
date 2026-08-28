<?php
namespace OWA\Module\Base\Controller;

/**
 * One action for every report.
 *
 * A report is reached as `do=base.report&reportId=pages`. The id is the
 * report's public identity; this controller turns it into whatever the registry
 * says it is.
 *
 * Today every registered report resolves to a controller, so this is pure
 * indirection and nothing renders differently. That is the point of doing it
 * first: the URL scheme, the nav links and the inter-report links can all move
 * to report ids before a single report becomes JSON, and each conversion after
 * that changes one registry entry rather than every link pointing at it.
 */
class Report extends \OWA\Core\Controller {

    /**
     * The reserved id space for user-authored reports: `custom-<row id>`.
     *
     * Sharing one id space with the shipped reports is what makes a custom
     * report an ordinary report everywhere else -- the same URL shape, the same
     * nav links, the same inter-report links, the same chrome.
     */
    const CUSTOM_PREFIX = 'custom-';

    function __construct( $params ) {

        parent::__construct( $params );

        /*
         * Deliberately NOT setting a capability here.
         *
         * Resolution is not the thing being authorised -- the report is, and
         * each report already declares its own requirement. Delegation happens
         * at doAction(), so the target's checkCapabilityAndAuthenticateUser()
         * runs against ITS capability. Requiring one here as well would mean a
         * report could be gated by two different answers, and the stricter one
         * would win by accident rather than by decision.
         */
    }

    /**
     * Resolve the id, then hand the whole request to whatever it names.
     *
     * Overriding doAction() rather than action() is what makes delegation
     * honest: doAction() is where the capability check, the update check and
     * the nonce check live, so the target gets all of them. Delegating at
     * action() would run this controller's checks and then the target's body,
     * which is a different and much worse thing.
     */
    function doAction() {

        $id = $this->getParam( 'reportId' );

        if ( ! $id ) {

            return $this->reportNotResolved(
                '(none)', 'no reportId was given', 400 );
        }

        /*
         * A custom report is addressed by the same reportId as any other, under
         * a reserved prefix: `custom-<row id>`. One id space, so every link,
         * bookmark and nav entry keeps working the same way, and a custom
         * report is shareable by URL for exactly the reason a shipped one is --
         * the URL is the whole address.
         *
         * Checked BEFORE the registry, so a module cannot register an id in the
         * reserved space and shadow somebody's saved report.
         */
        if ( strpos( $id, self::CUSTOM_PREFIX ) === 0 ) {

            return $this->renderCustom( $id );
        }

        $definition = \OWA\Core\CoreAPI::getReportDefinition( $id );

        if ( ! $definition ) {

            return $this->reportNotResolved(
                $id, 'not registered', 404 );
        }

        if ( ! empty( $definition['controller'] ) ) {

            return $this->delegateTo( $definition['controller'] );
        }

        if ( ! empty( $definition['json'] ) ) {

            return $this->renderConfigured( $id, $definition['json'] );
        }

        return $this->reportNotResolved(
            $id, 'is registered with neither a controller nor a definition', 500 );
    }

    /**
     * Load a JSON definition and render it through the one configured-report
     * controller.
     *
     * The file is read HERE rather than at registration time on purpose.
     * Module::registerReports() runs for every module that has reports, and
     * registration happens on requests that will never render one -- so
     * decoding at registration would put a json_decode() per report on every
     * request, tracker beacons included. Registration stores the path; exactly
     * one file is read, for the report actually asked for.
     */
    private function renderConfigured( $id, $path ) {

        if ( ! is_readable( $path ) ) {

            return $this->reportNotResolved(
                $id, sprintf( 'names a definition file that cannot be read: %s', $path ), 500 );
        }

        $definition = json_decode( (string) file_get_contents( $path ), true );

        if ( $definition === null && json_last_error() !== JSON_ERROR_NONE ) {

            return $this->reportNotResolved(
                $id, sprintf( 'has a definition file that is not valid JSON: %s', json_last_error_msg() ), 500 );
        }

        $error = \OWA\Core\ConfiguredReport::getDefinitionError( $definition );

        if ( $error !== '' ) {

            /*
             * Refused rather than rendered with the bad key ignored. A report
             * that quietly loses its title -- or its whole settings bag to a
             * misspelled "setings" -- looks like a styling bug and gets chased
             * in the wrong file.
             */
            return $this->reportNotResolved( $id, $error, 500 );
        }

        /*
         * A constraint parameter the request did not supply.
         *
         * 400 rather than 500: the definition is fine, the request is not. And
         * refused rather than rendered, because both silent outcomes are worse
         * than an error -- see ConfiguredReport::constraintParams().
         */
        $missing = array();

        foreach ( \OWA\Core\ConfiguredReport::constraintParams( $definition ) as $name ) {

            if ( (string) $this->getParam( $name ) === '' ) {

                $missing[] = $name;
            }
        }

        if ( $missing ) {

            return $this->reportNotResolved( $id,
                sprintf( 'is constrained on %s, which the request did not supply',
                    implode( ', ', $missing ) ),
                400 );
        }

        $target = new \OWA\Core\ConfiguredReport( $this->params );

        $target->setDefinition( $definition );

        return $target->doAction();
    }

    /**
     * Render a user-authored report.
     *
     * VIEWING IS NOT GATED ON AUTHORSHIP, deliberately. Ownership decides what
     * the roster LISTS and who may edit; a report reached by its URL renders
     * for anyone who may view reports, which is what "shareable by url" has to
     * mean to be true. It is safe because a custom report is a saved QUERY, not
     * saved data: every figure in it is one the reader could already have asked
     * for through the ordinary reporting UI, against a site the site filter
     * would already let them choose.
     *
     * The definition is re-validated on the way OUT, not trusted because it was
     * validated on the way in. Two reasons, and neither is hypothetical: the
     * registry changes -- a module deactivated since the report was saved takes
     * its metrics with it -- and a row can be edited by something that is not
     * the builder.
     */
    private function renderCustom( $id ) {

        $report = \OWA\Module\Base\Classes\CustomReports::load(
            substr( $id, strlen( self::CUSTOM_PREFIX ) ) );

        if ( ! $report ) {

            return $this->reportNotResolved( $id, 'not found', 404 );
        }

        $definition = (array) $report['definition'];

        $error = \OWA\Module\Base\Classes\CustomReports::validate( $definition );

        if ( $error !== '' ) {

            /*
             * 500, and named. A saved report that no longer validates is not
             * the reader's mistake, and rendering it with the bad part dropped
             * would show a report that is quietly missing a widget.
             */
            return $this->reportNotResolved( $id, $error, 500 );
        }

        $missing = array();

        foreach ( \OWA\Core\ConfiguredReport::constraintParams( $definition ) as $name ) {

            if ( (string) $this->getParam( $name ) === '' ) {

                $missing[] = $name;
            }
        }

        if ( $missing ) {

            return $this->reportNotResolved( $id,
                sprintf( 'is constrained on %s, which the request did not supply',
                    implode( ', ', $missing ) ),
                400 );
        }

        $target = new \OWA\Core\ConfiguredReport( $this->params );

        $target->setDefinition( $definition );

        /*
         * Which report this is, so the renderer can offer the things you can do
         * TO it -- editing it, and in time sharing and duplicating it. A shipped
         * report has no such row and gets no command bar.
         */
        $target->set( 'custom_report_id', $report['id'] );

        $data = (array) $target->doAction();

        /*
         * "Edit report", beside the title.
         *
         * It acts on the WHOLE report, which is what the title's line is for --
         * the same place the roster puts "New Custom Report".
         *
         * Offered only to someone who may actually edit. Viewing a custom
         * report is deliberately wider than editing one -- that is what
         * "shareable by url" means -- so an ungated control would be offering a
         * reader a link that leads to a refusal. Asked of mayEdit() against the
         * ROW, which is the same question CustomReportEdit asks when the link
         * is followed, so the two cannot answer differently.
         *
         * ASKED AFTER doAction(), AND THAT ORDER IS THE WHOLE POINT.
         *
         * This controller overrides doAction() and deliberately performs no
         * capability check of its own: it delegates so the TARGET's check
         * governs, which is what lets each report be gated by its own
         * requirement rather than by this one's. Authentication therefore
         * happens INSIDE the call above -- and until it has, the current user
         * is the default one. It carries the right user_id, and role
         * 'everyone'.
         *
         * Asked before it, isCapable('edit_users') was therefore always false,
         * so mayEdit could only ever succeed through the OWNERSHIP branch. An
         * admin opening somebody else's report got no edit control at all.
         *
         * Every test missed it for one reason: a test that builds a report and
         * then opens it is always looking at its own, where ownership alone is
         * enough. It took an admin opening a report created by someone else --
         * which is the ordinary case on any install with more than one author.
         */
        /*
         * Nothing to decorate unless a report was actually rendered. A refused
         * request comes back as the controller's bare data, and an edit control
         * on a refusal would be the second thing wrong with it.
         *
         * CoreAPI::isCurrentUserCapable() rather than reaching through
         * getCurrentUser() for isCapable(): it is the house way to ask this,
         * it always answers a bool, and it debug-logs the role and the
         * authentication state -- which is the exact pair that made this
         * ordering bug visible in the end.
         */
        $may_edit = ! empty( $data['view'] )
            && \OWA\Module\Base\Classes\CustomReports::mayEdit(
                $report,
                (string) \OWA\Core\CoreAPI::getCurrentUser()->getUserData( 'user_id' ),
                (bool) \OWA\Core\CoreAPI::isCurrentUserCapable( 'edit_users' )
            );

        if ( $may_edit ) {

            $data['title_actions'] = array(
                array(
                    'url'   => \OWA\Core\CoreAPI::supportClassFactory( 'base', 'template' )
                                   ->makeLink( array(
                                       'do'             => 'base.customReportEdit',
                                       'customReportId' => $report['id'],
                                   ), true ),
                    'label' => 'Edit report',
                    'icon'  => 'fa-pencil-alt',
                    // An icon, not a labelled button: the label names the
                    // report's own title, which is right beside it.
                    'iconOnly' => true,
                ),
            );
        }

        return $data;
    }

    /**
     * Build the controller an action name refers to and return ITS result.
     *
     * The same lookup CoreAPI::performAction() does, reused rather than
     * reimplemented: a report that resolves to an action must behave exactly as
     * it did when that action was reached directly.
     */
    private function delegateTo( $action ) {

        $service    = \OWA\Core\CoreAPI::serviceSingleton();
        $action_map = $service->getMapValue( 'actions', $action );

        if ( ! $action_map ) {

            return $this->reportNotResolved(
                $action, 'is registered against an action that is not registered', 500 );
        }

        $target = \OWA\Core\Lib::simpleFactory(
            $action_map['class_name'], $action_map['file'], $this->params );

        if ( ! $target || ! method_exists( $target, 'doAction' ) ) {

            return $this->reportNotResolved(
                $action, 'did not produce a controller', 500 );
        }

        return $target->doAction();
    }

    /**
     * Answer the way the rest of the dispatcher answers.
     *
     * CoreAPI::actionNotResolved() already notices, sets the status code and
     * renders base.error. Reusing it means an unresolvable report id is
     * indistinguishable from an unresolvable action, which is what it is --
     * and keeps this controller from inventing a second error convention.
     */
    private function reportNotResolved( $id, $message, $status ) {

        return \OWA\Core\CoreAPI::actionNotResolved(
            'base.report:' . $id,
            $status,
            new \Exception( $message )
        );
    }

}

?>
