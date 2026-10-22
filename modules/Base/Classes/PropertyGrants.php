<?php

namespace OWA\Module\Base\Classes;

/**
 * Who may see a Property, read and written at the Property.
 *
 * Grants are stored per Profile (owa_site_user), and reports are of a Property.
 * The two meet here: a user granted ANY Profile of a Property may see it -- the
 * same rule ServiceUser::withSiblingProfiles() applies when access is checked --
 * and a change made on the Property Access screen is written to EVERY Profile,
 * archived ones included, so a restored Profile comes back with the Property's
 * grants rather than its old ones.
 */
class PropertyGrants {

    /**
     * Ids of the users granted any Profile of the Property.
     *
     * @param  string $property_id
     * @return int[]
     */
    public static function userIds( $property_id ) {

        $ids = array();

        foreach ( self::sites( $property_id ) as $site ) {

            foreach ( $site->getAssignedUsers() as $user ) {

                $ids[ (int) $user->get( 'id' ) ] = true;
            }
        }

        return array_keys( $ids );
    }

    /**
     * Apply one submission of the access form to every Profile of the Property.
     *
     * Each Profile takes the same delta (Site::computeGrantChanges()), so a
     * user the form did not render is left alone on all of them, and a Profile
     * that already held a grant is not given a second row.
     *
     * @param  string $property_id
     * @param  array  $rendered user ids the form showed
     * @param  array  $checked  user ids it submitted ticked
     * @return int the number of Profiles written
     */
    public static function apply( $property_id, array $rendered, array $checked ) {

        $sites = self::sites( $property_id );

        foreach ( $sites as $site ) {

            $site->applyAssignedUserChanges( $rendered, $checked );
        }

        return count( $sites );
    }

    /**
     * Every Profile of the Property, archived or not.
     *
     * @param  string $property_id
     * @return \OWA\Module\Base\Entity\Site[]
     */
    private static function sites( $property_id ) {

        $out = array();

        foreach ( \OWA\Module\Base\Classes\Cube\Cubes::siteIds( $property_id ) as $site_id ) {

            $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
            $site->getByColumn( 'site_id', $site_id );

            if ( $site->get( 'id' ) ) {

                $out[] = $site;
            }
        }

        return $out;
    }
}
