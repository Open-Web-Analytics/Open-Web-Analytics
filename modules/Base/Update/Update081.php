<?php
namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Custom reports and visualizations belong to a Property.
 *
 * owa_custom_report gains property_id (BIGINT, indexed), and every row made
 * before it is given one, by the first rule that answers:
 *
 *   1. A funnel Update061 converted from 1.x: the Property of the Profile it
 *      was the funnel of. Its id is derived from that Profile's site_id and
 *      its goal slot (Update061::funnelId()), so the `goals` setting the
 *      conversion read names the Profile again.
 *   2. Any other row: the one Property its creator (user_id) may see, when
 *      there is exactly one. An administrator may see every live Property; any
 *      other user, those of the live Profiles granted to them (a grant on a
 *      Profile is one on its Property, ServiceUser::withSiblingProfiles()).
 *   3. Otherwise: the install's first Property. FIRST is the live Property
 *      with the lowest creation_date, one with none counting as oldest, then
 *      the lowest id; an archived one only when none is live.
 *
 * Each assignment is named in the notice, with its rule. A row is never
 * copied: ids stay, so a link to a report (do=base.report&reportId=custom-<id>)
 * goes on working, and one that names no Property opens on the report's own.
 * An install with no Property at all leaves its rows without one, and says so.
 *
 * On an upgrade from 1.x this runs after Update061 has made its funnels, and
 * Update061 writes them in raw SQL on the columns it knows, since this column
 * is not there yet.
 *
 * Idempotent: up() assigns only rows with no Property. down() drops the
 * column, which is the exact inverse, and finds nothing to drop when run again.
 *
 * RAW SQL on names spelled out here. The custom report entity is not
 * cachable, so no cached row is left without the column.
 *
 * NOT CLI-ONLY. Saved reports are a handful of rows.
 */
class Update081 extends \OWA\Core\Update {

    var $schema_version = 81;

    var $is_cli_mode_required = false;

    /** The column this adds. */
    const COLUMN = 'property_id';

    function up( $force = false ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.custom_report' );

        if ( ! $this->addColumnIfMissing( $entity, self::COLUMN ) ) {

            $this->e->notice( sprintf( 'Adding %s.%s failed.', $entity->getTableName(), self::COLUMN ) );

            return false;
        }

        $db    = \OWA\Core\CoreAPI::dbSingleton();
        $table = $this->table( 'custom_report' );

        $rows = (array) $db->get_results( sprintf(
            'SELECT id, name, user_id FROM %s WHERE property_id IS NULL OR property_id = 0 ORDER BY id', $table ) );

        if ( ! $rows ) {

            return true;
        }

        $funnels = $this->v1FunnelProperties();
        $live    = $this->liveProperties();
        $first   = $this->firstProperty();
        $seen    = array();

        foreach ( $rows as $row ) {

            $row = (array) $row;
            $id  = (string) $row['id'];

            $user_id = (string) $row['user_id'];

            if ( ! array_key_exists( $user_id, $seen ) ) {

                $seen[ $user_id ] = $this->propertiesOf( $user_id, $live );
            }

            $assignment = self::assign( $id, $funnels, $seen[ $user_id ], $first );

            if ( $assignment['property_id'] === '' ) {

                $this->e->notice( sprintf( 'Custom report "%s" (%s): left with no Property, as this install has none.',
                    $row['name'], $id ) );

                continue;
            }

            $ok = $db->query( sprintf( 'UPDATE %s SET property_id = ? WHERE id = ? AND ( property_id IS NULL OR property_id = 0 )',
                $table ), array( $assignment['property_id'], $id ) );

            if ( $ok === false ) {

                $this->e->notice( sprintf( 'Assigning custom report %s a Property failed.', $id ) );

                return false;
            }

            $this->e->notice( sprintf( 'Custom report "%s" (%s): Property %s, %s.',
                $row['name'], $id, $assignment['property_id'], $assignment['reason'] ) );
        }

        return true;
    }

    /** The column goes, with the Property each row had. */
    function down() {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.custom_report' );

        if ( ! $this->dropColumnIfPresent( $entity, self::COLUMN ) ) {

            $this->e->notice( sprintf( 'Dropping %s.%s failed.', $entity->getTableName(), self::COLUMN ) );

            return false;
        }

        return true;
    }

    /**
     * Which Property one row is given, and why. Pure, so the rules are tested
     * without a database.
     *
     * @param  string   $id         the row's id
     * @param  string[] $funnels    1.x funnel id => its Profile's Property
     * @param  string[] $owners     the Properties its creator may see
     * @param  string   $first      the install's first Property, or ''
     * @return array {property_id, reason}
     */
    public static function assign( $id, array $funnels, array $owners, $first ) {

        if ( isset( $funnels[ (string) $id ] ) && (string) $funnels[ (string) $id ] !== '' ) {

            return array( 'property_id' => (string) $funnels[ (string) $id ],
                'reason' => 'the Property of the Profile its 1.x funnel was of' );
        }

        $owners = array_values( array_unique( array_map( 'strval', $owners ) ) );

        if ( count( $owners ) === 1 ) {

            return array( 'property_id' => $owners[0], 'reason' => 'the one Property its creator may see' );
        }

        return array( 'property_id' => (string) $first, 'reason' => count( $owners )
            ? sprintf( "the install's first Property, as its creator may see %d", count( $owners ) )
            : "the install's first Property, as its creator may see none" );
    }

    /**
     * Each funnel Update061 converted, by id, and its Profile's Property: read
     * from the `goals` setting of each Profile, as the conversion read it.
     *
     * @return string[] id => property id
     */
    private function v1FunnelProperties() {

        $db  = \OWA\Core\CoreAPI::dbSingleton();
        $out = array();

        $rows = (array) $db->get_results( sprintf(
            "SELECT g.scope_id, g.value, s.property_id FROM %s g JOIN %s s ON s.site_id = g.scope_id"
            . " WHERE g.name = 'goals' AND g.scope_type = 'profile'",
            $this->table( 'setting' ), $this->table( 'site' ) ) );

        foreach ( $rows as $row ) {

            $row      = (array) $row;
            $property = (string) $row['property_id'];

            if ( $property === '' || $property === '0' ) {

                continue;
            }

            $goals = @unserialize( (string) $row['value'], array( 'allowed_classes' => false ) );

            if ( ! is_array( $goals ) ) {

                continue;
            }

            foreach ( $goals as $number => $goal ) {

                if ( ! is_array( $goal ) ) {

                    continue;
                }

                // The slot as Update061::planFunnels() reads it.
                $goal_number = (int) ( ( $goal['goal_number'] ?? '' ) ?: $number );

                $out[ Update061::funnelId( (string) $row['scope_id'], $goal_number ) ] = $property;
            }
        }

        return $out;
    }

    /** @return string[] the ids of every live Property */
    private function liveProperties() {

        $rows = (array) \OWA\Core\CoreAPI::dbSingleton()->get_results( sprintf(
            'SELECT id FROM %s WHERE archived_date IS NULL OR archived_date = 0 ORDER BY id', $this->table( 'property' ) ) );

        return array_map( static function ( $row ) {

            return (string) ( (array) $row )['id'];
        }, $rows );
    }

    /** @return string the install's first Property (see the class), or '' */
    private function firstProperty() {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        foreach ( array( 'WHERE archived_date IS NULL OR archived_date = 0', '' ) as $where ) {

            $row = (array) $db->get_row( sprintf( 'SELECT id FROM %s %s ORDER BY COALESCE(creation_date, 0), id LIMIT 1',
                $this->table( 'property' ), $where ) );

            if ( ! empty( $row['id'] ) ) {

                return (string) $row['id'];
            }
        }

        return '';
    }

    /**
     * The live Properties one user may see: every one for an administrator,
     * else those of the live Profiles granted to them. None for a user_id no
     * account has.
     *
     * @param  string   $user_id owa_user.user_id
     * @param  string[] $live    every live Property
     * @return string[]
     */
    private function propertiesOf( $user_id, array $live ) {

        $db = \OWA\Core\CoreAPI::dbSingleton();

        $user = (array) $db->get_row( sprintf( 'SELECT id, role FROM %s WHERE user_id = ?', $this->table( 'user' ) ),
            array( (string) $user_id ) );

        if ( empty( $user['id'] ) ) {

            return array();
        }

        if ( (string) $user['role'] === 'admin' ) {

            return $live;
        }

        $rows = (array) $db->get_results( sprintf(
            'SELECT DISTINCT s.property_id FROM %s su JOIN %s s ON s.id = su.site_id'
            . ' WHERE su.user_id = ? AND ( s.archived_date IS NULL OR s.archived_date = 0 )',
            $this->table( 'site_user' ), $this->table( 'site' ) ), array( $user['id'] ) );

        $out = array();

        foreach ( $rows as $row ) {

            $property = (string) ( (array) $row )['property_id'];

            if ( in_array( $property, $live, true ) ) {

                $out[] = $property;
            }
        }

        return $out;
    }

    /** A table's name under the install's prefix. */
    private function table( $name ) {

        return \OWA\Core\CoreAPI::getSetting( 'base', 'ns' ) . $name;
    }
}

?>
