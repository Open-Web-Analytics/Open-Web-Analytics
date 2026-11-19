<?php
/**
 * A partitioned entity of the test fixture module (TestFixtureModule.php) that
 * keeps its first months daily, as a cube does. Loaded with require_once;
 * CoreAPI::entityFactory() finds it as test_fixture.lead_probe.
 */

namespace OWA\Module\TestFixture\Entity;

class LeadProbe extends \OWA\Core\Entity {

    const MONTHS = 2;

    public function __construct() {

        $this->setTableName( 'test_fixture_lead_probe' );

        $id = new \OWA\Module\Base\Classes\DbColumn( 'id', OWA_DTD_BIGINT );
        $id->setPrimaryKey();
        $this->setProperty( $id );

        $this->setProperty( new \OWA\Module\Base\Classes\DbColumn( 'yyyymmdd', OWA_DTD_INT ) );
        $this->setPartitionColumn( 'yyyymmdd' );
    }

    public function getDailyLeadMonths() {

        return self::MONTHS;
    }
}
