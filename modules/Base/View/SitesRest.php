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
 * The website's human name is the Property's; a Profile's stored name is
 * "Observation Profile 1". So a client that wants to show which website a
 * Profile belongs to has it without a second call.
 */
class SitesRest extends \OWA\Core\View\RestApi {

    public function render() {

        $this->setResponseData( $this->addPropertyNames( $this->get( 'tracked_sites' ) ) );
    }

    /**
     * The Profiles as plain arrays, each with its Property's name.
     *
     * @param  array<\OWA\Module\Base\Entity\Site>|false $sites site_id => the Profile, as getSitesAllowedForCurrentUser() returns them; false when not set
     * @return array<array<string, mixed>> site_id => the Profile's columns and property_name
     */
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

        $names = [];

        foreach ( (array) $db->getAllRows() as $row ) {

            $names[ $row['id'] ] = $row['name'];
        }

        $out = [];

        foreach ( $sites as $key => $site ) {

            $row = (array) $site->getPublicProperties();

            /*
             * Always present, even when empty. A field that appears only
             * sometimes is worse than one that is sometimes blank: a consumer
             * cannot tell "no Property" from "this build does not send it".
             */
            $row['property_name'] = $names[ (string) $site->get( 'property_id' ) ] ?? '';

            $out[ $key ] = $row;
        }

        return $out;
    }
}
