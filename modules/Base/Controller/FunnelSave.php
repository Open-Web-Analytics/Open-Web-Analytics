<?php
namespace OWA\Module\Base\Controller;

/**
 * Create or update one funnel and its steps.
 */
class FunnelSave extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );
        $this->setRequiredCapability( 'edit_settings' );
        $this->setNonceRequired();
    }

    public function validate() {

        $this->addValidation( 'name', trim( (string) $this->getParam( 'name' ) ), 'required',
            array( 'errorMsg' => 'A funnel needs a name. It is what reports call the path.' ) );

        /*
         * The step rules, carried over from the goal screen this replaces.
         * Every one of them was earned by a bug -- see GoalEventSave, which
         * holds the same rules for the funnel it used to own.
         */
        $names = (array) $this->getParam( 'stepName' );
        $paths = (array) $this->getParam( 'stepPath' );

        $kept = 0;

        foreach ( $paths as $i => $path ) {

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
             * A path, not a URL. Every consumer matches on the path alone, so a
             * full web address matches nothing and the funnel reports zero.
             * Refused rather than silently trimmed.
             */
            if ( $path !== '' && preg_match( '~^[a-z][a-z0-9+.\-]*://~i', $path ) ) {

                $this->addValidation( 'stepPath' . $number, '', 'required', array(
                    'errorMsg' => sprintf(
                        'Step %s: enter the page PATH, such as /basket -- not a full web address. '
                        . 'Funnel steps are matched on the path alone.', $number ),
                ) );
            }
        }

        /*
         * A funnel with no steps describes no path. Refused rather than stored,
         * because it would sit in the list with nothing to show.
         */
        if ( ! $kept ) {

            $this->addValidation( 'stepPath1', '', 'required',
                array( 'errorMsg' => 'A funnel needs at least one step.' ) );
        }
    }

    function action() {

        $siteId = $this->getParam( 'siteId' );
        $id     = $this->getParam( 'funnelId' );

        $funnel = \OWA\Core\CoreAPI::entityFactory( 'base.funnel' );

        if ( $id ) {

            $funnel->load( $id );
        }

        $funnel->set( 'property_id',
            \OWA\Module\Base\Classes\GoalManager::propertyFor( $siteId ) );
        $funnel->set( 'name', trim( (string) $this->getParam( 'name' ) ) );

        /*
         * Empty is a real answer: a funnel that counts as nothing is a path
         * analysis. Stored as empty rather than skipped, so clearing the
         * picker actually clears it.
         */
        $funnel->set( 'goal_event_id', $this->getParam( 'goalEventId' ) ?: '' );

        if ( $funnel->wasPersisted() ) {

            $funnel->update();

        } else {

            $id = $funnel->generateId( 'funnel:' . $siteId . ':' . uniqid( '', true ) );

            $funnel->set( 'id', $id );
            $funnel->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
            $funnel->create();
        }

        $this->saveSteps( $funnel->get( 'id' ) );

        $this->set( 'siteId', $siteId );
        $this->setRedirectAction( 'base.funnels' );
        $this->set( 'status_code', 3201 );
    }

    /**
     * Replace the steps with what was submitted.
     *
     * Delete-then-write: a funnel is ORDERED and short, and a step's identity
     * is its position -- there is no stable key to match a submitted step
     * against a stored one, and removing a middle step renumbers everything
     * after it.
     */
    private function saveSteps( $funnelId ) {

        if ( ! $funnelId ) {

            return;
        }

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.funnel_step' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->deleteFrom( $entity->getTableName() );
        $db->where( 'funnel_id', $funnelId );
        $db->executeQuery();

        $names  = (array) $this->getParam( 'stepName' );
        $number = 0;

        foreach ( (array) $this->getParam( 'stepPath' ) as $i => $path ) {

            $path = trim( (string) $path );

            if ( $path === '' ) {

                continue;
            }

            $number++;

            $step = \OWA\Core\CoreAPI::entityFactory( 'base.funnel_step' );

            $step->set( 'id', $step->generateId( 'funnel_step:' . $funnelId . ':' . $number ) );
            $step->set( 'funnel_id', $funnelId );
            $step->set( 'step_number', $number );
            $step->set( 'name', trim( (string) ( $names[ $i ] ?? '' ) ) );
            $step->set( 'condition_property',
                \OWA\Module\Base\Entity\GoalEvent::PROPERTY_PAGE_URI );
            $step->set( 'condition_operator',
                \OWA\Module\Base\Entity\GoalEvent::MATCH_REGEX );
            $step->set( 'condition_value', $path );
            $step->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
            $step->create();
        }
    }

    function errorAction() {

        $siteId = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );

        $steps = array();
        $names = (array) $this->getParam( 'stepName' );

        foreach ( (array) $this->getParam( 'stepPath' ) as $i => $path ) {

            $steps[] = array( 'name' => $names[ $i ] ?? '', 'path' => $path );
        }

        $this->set( 'funnel', array(
            'id'            => $this->getParam( 'funnelId' ),
            'name'          => $this->getParam( 'name' ),
            'goal_event_id' => $this->getParam( 'goalEventId' ),
        ) );

        $this->set( 'funnelId', $this->getParam( 'funnelId' ) );
        $this->set( 'steps', $steps );
        $this->set( 'goalEvents', GoalEvents::listFor( $siteId ) );
        $this->set( 'siteId', $siteId );
        $this->set( 'params', array_merge( (array) $this->params, array( 'siteId' => $siteId ) ) );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        $this->set( 'hierarchy_tier', 2 );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( $siteId ) );
        $this->set( 'error_code', 3002 );
        $this->setView( 'base.optionsHierarchy' );
        $this->setSubview( 'base.funnelEdit' );
    }
}
