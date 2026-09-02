<?php
namespace OWA\Module\Base\Controller;

/**
 * The key events of one Observation Profile.
 *
 * Replaces the goals screen, which listed twenty numbered slots whether or not
 * anyone had filled them in -- because the storage was a fixed-length array and
 * the screen showed the storage. This lists what exists.
 *
 * "Key event" rather than "goal" is v2's name for the same idea, and GA's:
 * conversions were renamed key events in 2024. Naming it that here means the
 * screen does not have to be relearned when v2 lands.
 */
class KeyEvents extends \OWA\Core\AdminController {

    function __construct( $params ) {

        parent::__construct( $params );
        $this->type = 'options';
        $this->setRequiredCapability( 'edit_settings' );
    }

    function action() {

        $siteId = $this->resolveCurrentSiteId( $this->getParam( 'siteId' ) );

        $this->set( 'siteId', $siteId );
        $this->set( 'keyEvents', self::listFor( $siteId ) );

        $this->set( 'params', array_merge( (array) $this->params, array( 'siteId' => $siteId ) ) );
        $this->set( 'site_hierarchy', $this->getSiteHierarchy( $this->getSitesAllowedForCurrentUser() ) );
        /* Tier 3: a key event belongs to one Observation Profile. */
        $this->set( 'hierarchy_tier', 3 );
        $this->set( 'hierarchy_nav', $this->getHierarchyNav( $siteId ) );
        $this->setView( 'base.optionsHierarchy' );
        $this->setSubview( 'base.keyEvents' );
    }

    /**
     * One Profile's key events, newest slot last.
     *
     * Rows, not slots: a Profile with two key events has two, and one with none
     * has none rather than twenty blanks.
     *
     * @return array
     */
    public static function listFor( $siteId ) {

        if ( ! $siteId ) {

            return array();
        }

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.key_event' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $entity->getTableName() );
        $db->selectColumn( '*' );
        $db->where( 'site_id', $siteId );
        $db->orderBy( 'name' );

        return (array) $db->getAllRows();
    }
}
