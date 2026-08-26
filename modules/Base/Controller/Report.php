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
