<?php
namespace OWA\Module\Base\Classes\Migration;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * The v1 (1.x) tracking tables, by name.
 *
 * v2 carries no v1 entity classes. What remains of v1 after an upgrade is
 * these tables: the migrator reads them with SQL and the optional drop command
 * removes them. This list is the one place that names them.
 *
 * COLUMN NAMES ARE THE CONTRACT, NOT TYPES. Installations built at different
 * times hold the same columns with different types -- a dimension foreign key
 * is BIGINT on one and VARCHAR(255) on another -- so nothing reading these
 * tables may depend on a column's type. See PLAN.html 2.21.
 */
class V1Tables {

    /** One row per tracked interaction, and the other event-level facts. */
    const FACTS = array(
        'request',
        'session',
        'click',
        'action_fact',
        'feed_request',
        'impression',
        'domstream',
        'commerce_transaction_fact',
        'commerce_line_item_fact',
    );

    /** What the facts point at by hashed id. */
    const DIMENSIONS = array(
        'visitor',
        'document',
        'ua',
        'os',
        'host',
        'referer',
        'location_dim',
        'source_dim',
        'campaign_dim',
        'ad_dim',
        'search_term_dim',
    );

    /** @return string[] every v1 table, unprefixed */
    public static function all() {

        return array_merge( self::FACTS, self::DIMENSIONS );
    }

    /**
     * @param  string $table  one of all()
     * @param  string $prefix the installation's table prefix
     * @return string
     */
    public static function name( $table, $prefix = 'owa_' ) {

        if ( ! in_array( $table, self::all(), true ) ) {

            throw new \InvalidArgumentException( sprintf( '%s is not a v1 table', $table ) );
        }

        return $prefix . $table;
    }

    /**
     * Which v1 tables this database still holds.
     *
     * @param  object $db
     * @param  string $prefix
     * @return string[] unprefixed
     */
    public static function present( $db, $prefix = 'owa_' ) {

        return array_values( array_filter( self::all(), function ( $table ) use ( $db, $prefix ) {

            return $db->tableExists( $prefix . $table );
        } ) );
    }
}

?>
