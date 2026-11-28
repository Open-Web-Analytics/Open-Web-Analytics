<?php
namespace OWA\Module\Base\Classes;

/**
 * Archiving a Property and restoring it: what the Property screen's delete
 * (Controller\PropertyDelete) and cmd=property (Controller\PropertyCli) do.
 *
 * ARCHIVING cascades downward. The Property and each of its live Profiles
 * take one archived_date, and each of those Profiles' tracking bundles is
 * removed, as SitesDelete removes one. Nothing else changes: grants, scoped
 * settings and collected data stay where they are. Archiving the last
 * Profile of a Property does not archive the Property.
 *
 * RESTORING undoes one archive. The Property's archived_date is cleared, and
 * that of each Profile with the same archived_date: a Profile archived on its
 * own before is left archived. Each restored web Profile's bundle is
 * published again. Refused while another live Property has the domain's host
 * (Entity\Property::LIVE_HOST_INDEX).
 *
 * archived_date is cleared to 0, not NULL: the entity layer skips a NULL, and
 * every reader takes a falsy value as live (Entity\Property::isArchived()).
 *
 * Both are harmless to repeat: a second run finds nothing to change.
 */
class PropertyArchive {

    /**
     * Archive a Property and its live Profiles.
     *
     * Under a Property already archived, a Profile still live takes the
     * Property's archived_date, so a restore brings it back with the rest.
     *
     * @param  int|string $property_id
     * @return array{found: bool, property: bool, archived_date: int, profiles: list<string>}
     *         whether the Property exists; whether this archived it; its
     *         archived_date; the site_ids of the Profiles this archived
     */
    public static function archive( $property_id ) {

        $out = [ 'found' => false, 'property' => false, 'archived_date' => 0, 'profiles' => [] ];

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );
        $property->load( $property_id );

        if ( ! $property->wasPersisted() ) {

            return $out;
        }

        $out['found'] = true;

        if ( ! $property->isArchived() ) {

            $property->set( 'archived_date', \OWA\Core\CoreAPI::getRequestTimestamp() );

            if ( ! $property->update() ) {

                return $out;
            }

            $out['property'] = true;
        }

        $out['archived_date'] = (int) $property->get( 'archived_date' );

        /*
         * One at a time rather than with a single UPDATE ... WHERE
         * property_id: an entity write keeps the row cache and the entity's
         * own update path in step, and a Property has few Profiles.
         */
        foreach ( self::profiles( $property_id ) as $profile ) {

            if ( $profile->isArchived() ) {

                continue;
            }

            $profile->set( 'archived_date', $out['archived_date'] );

            if ( $profile->update() ) {

                $out['profiles'][] = (string) $profile->get( 'site_id' );

                // An archived Profile stops collecting, so its tracking bundle is queued to be taken down.
                TrackerBundle::remove( (string) $profile->get( 'site_id' ) );
            }
        }

        return $out;
    }

    /**
     * Restore an archived Property and the Profiles archived with it.
     *
     * @param  int|string $property_id
     * @return array{found: bool, property: bool, profiles: list<string>, refused: string}
     *         whether the Property exists; whether this restored it; the
     *         site_ids of the Profiles this restored; why it was refused, ''
     *         when it was not
     */
    public static function restore( $property_id ) {

        $out = [ 'found' => false, 'property' => false, 'profiles' => [], 'refused' => '' ];

        $property = \OWA\Core\CoreAPI::entityFactory( 'base.property' );
        $property->load( $property_id );

        if ( ! $property->wasPersisted() ) {

            return $out;
        }

        $out['found'] = true;

        if ( ! $property->isArchived() ) {

            return $out;
        }

        if ( $property->isWebProperty() ) {

            $out['refused'] = SiteManager::domainTakenMessage( $property->get( 'domain' ), $property_id );

            if ( $out['refused'] !== '' ) {

                return $out;
            }
        }

        $archived_at = (int) $property->get( 'archived_date' );

        $property->set( 'archived_date', 0 );

        if ( ! $property->update() ) {

            $out['refused'] = \OWA\Core\CoreAPI::t( 'The Property could not be saved.' );

            return $out;
        }

        $out['property'] = true;

        foreach ( self::profiles( $property_id ) as $profile ) {

            if ( (int) $profile->get( 'archived_date' ) !== $archived_at ) {

                continue;
            }

            $profile->set( 'archived_date', 0 );

            if ( $profile->update() ) {

                $out['profiles'][] = (string) $profile->get( 'site_id' );

                if ( $profile->getStreamType() === \OWA\Module\Base\Entity\Site::STREAM_WEB ) {

                    TrackerBundle::publishNow( (string) $profile->get( 'site_id' ) );
                }
            }
        }

        return $out;
    }

    /**
     * A Property's Profiles, live and archived, in id order.
     *
     * @param  int|string $property_id
     * @return list<\OWA\Module\Base\Entity\Site>
     */
    public static function profiles( $property_id ) {

        // Db::where() drops an empty value, which would read every Profile.
        if ( \OWA\Core\Lib::isEmpty( $property_id ) ) {

            return [];
        }

        $site = \OWA\Core\CoreAPI::entityFactory( 'base.site' );

        $db = \OWA\Core\CoreAPI::dbSingleton();
        $db->selectFrom( $site->getTableName() );
        $db->selectColumn( 'id' );
        $db->where( 'property_id', $property_id );
        $db->orderBy( 'id', 'ASC' );

        $out = [];

        foreach ( (array) $db->getAllRows() as $row ) {

            $profile = \OWA\Core\CoreAPI::entityFactory( 'base.site' );
            $profile->load( $row['id'] );

            if ( $profile->wasPersisted() ) {

                $out[] = $profile;
            }
        }

        return $out;
    }
}
