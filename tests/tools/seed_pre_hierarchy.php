<?php
/**
 * Puts an installed instance back into its pre-hierarchy shape, then seeds the
 * cases Update020 has to get right.
 *
 * WHY A REGRESSION RATHER THAN A FIXTURE
 * --------------------------------------
 * A fresh install now arrives already at schema 20, with the hierarchy in
 * place, so there is no way to install "the old shape" -- the only way to reach
 * it is to take a current install and undo it. That is what this does: drop the
 * two tables, drop the two link columns, and set the module's stored schema
 * version back to 19 so the update is considered outstanding again.
 *
 * MUST NOT BE RUN AGAINST AN INSTALL ANYONE CARES ABOUT. The migration
 * overwrites every site's name with "Observation Profile N" -- the site's own
 * name moves up to its property -- so running it against the reporting install
 * would rewrite exactly the labels those fixtures assert on. Guarded below:
 * this refuses to run unless the database name looks like a scratch one.
 *
 * Usage: php tests/tools/seed_pre_hierarchy.php [--force]
 */

require_once dirname( __DIR__, 2 ) . '/owa.php';

$owa = new owa( array( 'instance_role' => 'cli' ) );

$db = owa_coreAPI::dbSingleton();

$dbName = (string) owa_coreAPI::getSetting( 'base', 'db_name' );

$looksScratch = ( stripos( $dbName, 'scratch' ) !== false )
             || ( stripos( $dbName, 'owa_test' ) !== false )
             || ( stripos( $dbName, 'tmp' ) !== false );

if ( ! $looksScratch && ! in_array( '--force', $argv, true ) ) {

    fwrite( STDERR,
        "refusing: '$dbName' does not look like a scratch database.\n"
      . "This drops tables and rewrites every site's name. Pass --force only if\n"
      . "you are certain, and never against an install with data you want.\n" );

    exit( 2 );
}

/* ---- 1. undo the hierarchy ------------------------------------------------ */

foreach ( array( 'owa_property', 'owa_organization' ) as $table ) {

    $db->query( "DROP TABLE IF EXISTS $table" );
}

foreach ( array( array( 'owa_site', 'property_id' ), array( 'owa_user', 'organization_id' ) ) as $pair ) {

    list( $table, $column ) = $pair;

    /* Tolerated rather than checked: the column is absent on a genuinely old
       install, which is the state being reproduced. */
    $db->query( "ALTER TABLE $table DROP COLUMN $column" );
}

owa_coreAPI::persistSetting( 'base', 'schema_version', 19 );

/* ---- 2. seed the cases the plan has to distinguish ------------------------ */

$seed = array(
    /* One website reached two ways -- the case the hierarchy exists for, and
       one only possible since identity stopped being md5( domain ). */
    array( 'site_id' => 'seed-http',      'domain' => 'http://seedsite.example',  'name' => 'Seed Site' ),
    array( 'site_id' => 'seed-https',     'domain' => 'https://seedsite.example', 'name' => 'Seed Site SSL' ),
    /* Differs only by case and a trailing slash. */
    array( 'site_id' => 'seed-case',      'domain' => 'https://SeedSite.example/', 'name' => 'Seed Site Caps' ),
    /* A separate website that happens to share a name. */
    array( 'site_id' => 'seed-other',     'domain' => 'other.example',            'name' => 'Seed Site' ),
    /* Domainless, as 'owa-test-site' is in the wild. */
    array( 'site_id' => 'seed-nodomain',  'domain' => '',                          'name' => 'No Domain' ),
    /* Unnamed, so the property must fall back to the domain. */
    array( 'site_id' => 'seed-unnamed',   'domain' => 'unnamed.example',           'name' => '' ),
);

foreach ( $seed as $row ) {

    $site = owa_coreAPI::entityFactory( 'base.site' );

    $id = $site->generateId( $row['site_id'] );

    $site->load( $id );

    if ( $site->wasPersisted() ) {

        continue;
    }

    $site->set( 'id', $id );
    $site->set( 'site_id', $row['site_id'] );

    if ( $row['domain'] !== '' ) {
        $site->set( 'domain', $row['domain'] );
    }

    if ( $row['name'] !== '' ) {
        $site->set( 'name', $row['name'] );
    }

    $site->create();
}

echo json_encode( array(
    'status'         => 'seeded pre-hierarchy',
    'database'       => $dbName,
    'schema_version' => owa_coreAPI::getSetting( 'base', 'schema_version' ),
    'seeded_sites'   => count( $seed ),
    'expected_after' => array(
        /* seedsite.example collapses three ways of writing it into one property
           with three profiles; other.example and the two odd ones stand alone. */
        'properties' => 4,
        'profiles'   => 6,
    ),
), JSON_PRETTY_PRINT ), "\n";
