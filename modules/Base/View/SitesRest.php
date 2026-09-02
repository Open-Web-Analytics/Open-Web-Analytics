<?php

namespace OWA\Module\Base\View;

/**
 * GET /v1/sites
 *
 * A FLAT list of Observation Profiles, because a Property has no tracker id and
 * the caller's whole purpose is to choose one to put in a tag.
 *
 * 'property_name' is ADDED to each entry; nothing existing is renamed, removed
 * or rewritten. Adding is safe -- a JSON consumer ignores keys it does not know
 * -- whereas changing what a published field CONTAINS is not, even though the
 * shape would survive it.
 *
 * It is needed because the hierarchy moved the website's human name up to the
 * Property, leaving the Profile's stored name as "Observation Profile 1". A
 * client that wants to show which website a Profile belongs to now has it
 * without a second call.
 *
 * Note this is NOT about the WordPress plugin's picker, whatever the earlier
 * reasoning here said. That picker labels with site_id and domain and never
 * reads 'name' at all:
 *
 *     sprintf('%s (%s)', $site['properties']['site_id']['value'],
 *                        $site['properties']['domain']['value'])
 *
 * -- which also shows it is reading the PRE-#977 nested shape and has been
 * broken since that payload was flattened. Fixing that belongs in the plugin,
 * not here.
 */
class SitesRest extends \OWA\Core\View\RestApi {

    function render() {

        $this->setResponseData( $this->addPropertyNames( $this->get( 'tracked_sites' ) ) );
    }

    private function addPropertyNames( $sites ) {

        /*
         * Entities are reduced to plain arrays HERE rather than handed on as
         * entities, because Entity::set() silently drops a key that is not a
         * declared column -- so setting property_name on the entity would have
         * added nothing to the payload at all, with no error to say so.
         *
         * RestApi::toResponseData() leaves a plain array untouched, so this
         * takes over the reduction it would otherwise do and nothing else
         * changes.
         */

        $sites = (array) $sites;

        if ( ! $sites ) {

            return $sites;
        }

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $property->getTableName() );
        $db->selectColumn( 'id, name' );

        $names = array();

        foreach ( (array) $db->getAllRows() as $row ) {

            $names[ $row['id'] ] = $row['name'];
        }

        $out = array();

        foreach ( $sites as $key => $site ) {

            $row = (array) $site->_getProperties();

            /*
             * Always present, even when empty. A field that appears only
             * sometimes is worse than one that is sometimes blank: a consumer
             * cannot tell "no Property" from "this build does not send it".
             */
            $row['property_name'] = $names[ $site->get( 'property_id' ) ] ?? '';

            $out[ $key ] = $row;
        }

        return $out;
    }
}

?>
