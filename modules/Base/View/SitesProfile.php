<?php
namespace OWA\Module\Base\View;

/**
 *  Sites Profile View
 * 
 *
 * @phpstan-import-type Row from \OWA\Core\Db
 */

class SitesProfile extends \OWA\Core\View {

    /**
     * @return void
     */
    public function render() {


        $site = $this->get('site');
        if ($this->get('edit')) {
            $this->body->set('action', 'base.sitesEdit');
            $this->body->set('headline', \OWA\Core\CoreAPI::t( 'Profile Details' ));

            $siteEntity = \OWA\Module\Base\Entity\Site::forSiteId( $this->get('siteId') );
            $this->body->set('siteEntity', $siteEntity);

        } else {
            $this->body->set('action', 'base.sitesAdd');
            $this->body->set('headline', \OWA\Core\CoreAPI::t( 'New Observation Profile' ));

        }
        // Keyed on the Profile, not on a domain: the add form can arrive with
        // its Property's domain filled in.
        if ($this->get('siteId')) {
            $this->t->set( 'page_title', \OWA\Core\CoreAPI::t( 'Profile Details' ) );
        }
        else {
            $this->t->set( 'page_title', \OWA\Core\CoreAPI::t( 'New Observation Profile' ) );
        }

        $this->body->set('users', $this->getAllUserRows());

        // Normalised to arrays: both are already set unconditionally, but either
        // can arrive null on the add path, and the template indexes into them.
        $this->body->set( 'site', $site ?? [] );
        $this->body->set( 'edit', $this->get('edit') );

        /*
         * The Properties this Profile could belong to, and which one is
         * pre-selected. Live ones only -- an archived Property is a removed
         * Property and putting a new Profile into one would resurrect it
         * halfway.
         */
        $this->body->set( 'properties', $this->getLivePropertyRows() );
        $this->body->set( 'propertyId', (string) ( $this->get('propertyId') ?? '' ) );
        $this->body->set( 'propertyName', $this->resolvePropertyName( $site['property_id'] ?? '' ) );
        $this->body->set( 'site_id', $this->get('siteId') );
        $this->body->set( 'config', $this->get('config') ?? [] );
        $this->body->set_template( 'sites_addoredit.php' );
    }

    /**
     * Properties that can still be observed, ordered as the site control orders
     * them so the two read the same way.
     *
     * @return list<Row>
     */
    private function getLivePropertyRows() {

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $property->getTableName() );
        $db->selectColumn( 'id, name, domain, archived_date' );
        // ASC explicitly: orderBy() with no direction is DESC.
        $db->orderBy( 'name', OWA_SQL_ASCENDING );

        $rows = [];

        foreach ( (array) $db->getAllRows() as $row ) {

            if ( empty( $row['archived_date'] ) ) {

                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * The name of the Property a Profile belongs to, for the read-only line on
     * the edit form.
     *
     * @param  string $propertyId the Profile's property_id; '' for none
     * @return string
     */
    private function resolvePropertyName( $propertyId ) {

        if ( ! $propertyId ) {

            return '';
        }

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );
        $property->load( $propertyId );

        return (string) $property->get( 'name' );
    }

    /**
     * @return list<Row>|null null for no rows
     */
    private function getAllUserRows() {
        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( \OWA\Core\CoreAPI::entityFactory( 'base.user' )->getTableName() );
        $db->selectColumn("*");
        return $db->getAllRows();
    }

}
