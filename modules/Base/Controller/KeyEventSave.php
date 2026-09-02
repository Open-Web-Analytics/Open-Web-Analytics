<?php
namespace OWA\Module\Base\Controller;

/**
 * Create or update one key event.
 */
class KeyEventSave extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );
        $this->setRequiredCapability( 'edit_settings' );
        $this->setNonceRequired();
    }

    public function validate() {

        $this->addValidation( 'name', trim( (string) $this->getParam( 'name' ) ), 'required',
            array( 'errorMsg' => 'A key event needs a name. It is what reports call it.' ) );

        /*
         * A condition with no value counts nothing, and says nothing about it.
         * This install had a goal in exactly that state -- a type the evaluator
         * has no case for and no URL -- silently never firing since it was
         * made. Refusing it here is the cheapest place to notice.
         */
        $this->addValidation( 'conditionValue', trim( (string) $this->getParam( 'conditionValue' ) ),
            'required',
            array( 'errorMsg' => 'Without something to compare against, this would count nothing.' ) );
    }

    function action() {

        $siteId = $this->getParam( 'siteId' );
        $id     = $this->getParam( 'keyEventId' );

        $keyEvent = \OWA\Core\CoreAPI::entityFactory( 'base.key_event' );

        if ( $id ) {

            $keyEvent->load( $id );
        }

        $cents = \OWA\Module\Base\Entity\KeyEvent::decimalToCents( $this->getParam( 'value' ) );

        $keyEvent->set( 'site_id', $siteId );
        $keyEvent->set( 'name', trim( (string) $this->getParam( 'name' ) ) );
        $keyEvent->set( 'condition_property', $this->getParam( 'conditionProperty' ) );
        $keyEvent->set( 'condition_operator', $this->getParam( 'conditionOperator' ) );
        $keyEvent->set( 'condition_value', trim( (string) $this->getParam( 'conditionValue' ) ) );
        $keyEvent->set( 'value', $cents === null ? 0 : $cents );
        $keyEvent->set( 'is_active', $this->getParam( 'isActive' ) ? 1 : 0 );

        /*
         * The event type the condition is evaluated against. Fixed for now,
         * because 1.x has exactly one implemented goal type and evaluates it
         * against page requests -- but stored per row rather than assumed, so
         * v2 can offer a choice without a migration.
         */
        if ( ! $keyEvent->get( 'trigger_event_type' ) ) {

            $keyEvent->set( 'trigger_event_type',
                \OWA\Module\Base\Entity\KeyEvent::TRIGGER_PAGE_VIEW );
        }

        if ( $keyEvent->wasPersisted() ) {

            $keyEvent->update();

        } else {

            /*
             * A NEW key event takes the next free numbered slot if there is one,
             * and none at all once twenty are used.
             *
             * The 45 goal{N} metrics resolve by number, so a key event with a
             * slot can be reported through them and one without cannot. Giving
             * out slots while they last means nothing REGRESSES -- what could
             * be reported before still can -- without capping key events at
             * twenty the way the slots did.
             */
            $keyEvent->set( 'id', $keyEvent->generateId(
                'key_event:' . $siteId . ':' . uniqid( '', true ) ) );
            $keyEvent->set( 'goal_number', self::nextFreeSlot( $siteId ) );
            $keyEvent->set( 'creation_date', \OWA\Core\CoreAPI::getRequestTimestamp() );
            $keyEvent->create();
        }

        $this->set( 'siteId', $siteId );
        $this->setRedirectAction( 'base.keyEvents' );
        $this->set( 'status_code', 3201 );
    }

    /**
     * The lowest numbered slot this Profile is not using, or null past twenty.
     *
     * @return int|null
     */
    public static function nextFreeSlot( $siteId ) {

        $taken = array();

        foreach ( KeyEvents::listFor( $siteId ) as $row ) {

            if ( (int) $row['goal_number'] > 0 ) {

                $taken[ (int) $row['goal_number'] ] = true;
            }
        }

        for ( $i = 1; $i <= 20; $i++ ) {

            if ( ! isset( $taken[ $i ] ) ) {

                return $i;
            }
        }

        return null;
    }

    function errorAction() {

        $siteId = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );

        /* Re-render the form with what was typed, rather than losing it. */
        $this->set( 'keyEvent', array(
            'id'                 => $this->getParam( 'keyEventId' ),
            'name'               => $this->getParam( 'name' ),
            'condition_property' => $this->getParam( 'conditionProperty' ),
            'condition_operator' => $this->getParam( 'conditionOperator' ),
            'condition_value'    => $this->getParam( 'conditionValue' ),
            'value'              => \OWA\Module\Base\Entity\KeyEvent::decimalToCents(
                                        $this->getParam( 'value' ) ) ?: 0,
            'is_active'          => $this->getParam( 'isActive' ) ? 1 : 0,
        ) );

        $this->set( 'keyEventId', $this->getParam( 'keyEventId' ) );
        $this->set( 'siteId', $siteId );
        $this->set( 'conditionProperties', KeyEventEdit::conditionProperties() );
        $this->set( 'params', array_merge( (array) $this->params, array( 'siteId' => $siteId ) ) );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        $this->set( 'hierarchy_tier', 3 );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( $siteId ) );
        $this->set( 'error_code', 3002 );
        $this->setView( 'base.optionsHierarchy' );
        $this->setSubview( 'base.keyEventEdit' );
    }
}
