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

        $definition = \OWA\Core\CoreAPI::getReportDefinition( $id );

        if ( ! $definition ) {

            return $this->reportNotResolved(
                $id, 'not registered', 404 );
        }

        if ( ! empty( $definition['controller'] ) ) {

            return $this->delegateTo( $definition['controller'] );
        }

        /*
         * A JSON definition. Nothing renders it yet -- that arrives with the
         * widget renderer -- and answering "not resolved" is better than
         * answering with a blank page, because a half-built path that returns
         * something looks like it works.
         */
        return $this->reportNotResolved(
            $id, 'is configuration, which this build cannot render yet', 501 );
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
