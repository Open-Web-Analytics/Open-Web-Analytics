<?php
namespace OWA\Module\Base\Entity;

/**
 * owa_spam_block: how many beacons spam detection blocked, per day, Profile
 * and rule.
 *
 * A blocked beacon writes no row to owa_event_raw, so this is the only record
 * that it arrived. One row per (yyyymmdd, site_id, rule): `rule` is a rule's
 * name, counting the blocked beacons that met it, or `*`, counting the blocked
 * beacons themselves. A beacon that met two rules adds one to each and one to
 * `*`, so the rule rows do not sum to `*`.
 *
 * Written by Classes\Spam\Blocks with INSERT ... ON DUPLICATE KEY UPDATE, and
 * never pruned: a row per day per Profile per rule that fired.
 *
 * yyyymmdd is derived as raw's is (TrackingEventHelpers::deriveYyyymmdd()),
 * from the beacon's edge receipt, so a day here is the same day as in raw.
 */
class SpamBlock extends \OWA\Core\Entity {

    /** The rule name of the row that counts blocked beacons. */
    const TOTAL = '*';

    public function __construct() {

        $this->setTableName( 'spam_block' );

        $yyyymmdd = new \OWA\Module\Base\Classes\DbColumn( 'yyyymmdd', OWA_DTD_INT );
        $yyyymmdd->setNotNull();
        $this->setProperty( $yyyymmdd );

        // As wide as owa_event_raw.site_id.
        $site_id = new \OWA\Module\Base\Classes\DbColumn( 'site_id', OWA_DTD_VARCHAR64 );
        $site_id->setNotNull();
        $this->setProperty( $site_id );

        $rule = new \OWA\Module\Base\Classes\DbColumn( 'rule', OWA_DTD_VARCHAR64 );
        $rule->setNotNull();
        $this->setProperty( $rule );

        $blocked = new \OWA\Module\Base\Classes\DbColumn( 'blocked', OWA_DTD_BIGINT );
        $blocked->setNotNull();
        $blocked->setDefaultValue( 0 );
        $this->setProperty( $blocked );

        $this->setCompositePrimaryKey( [ 'yyyymmdd', 'site_id', 'rule' ] );
    }
}
