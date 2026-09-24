<?php
/**
 * Boots OWA with OWA_TIMEZONE defined, against a stored timezone row, and
 * reports what wins. Spawned by SettingsConfigConstantTest -- a constant is a
 * process-global define, so this cannot be done in the runner's process.
 *
 * Excluded from the release tarball with the rest of tests/.
 */

define( 'OWA_TIMEZONE', 'Pacific/Auckland' );

require dirname( __DIR__ ) . '/bootstrap_owa.php';

$db = owa_coreAPI::dbSingleton();
$c  = owa_coreAPI::configSingleton();

$entity = owa_coreAPI::entityFactory( 'base.setting' );
$table  = $entity->getTableName();
$id     = $db->prepare( (string) $entity->makeId( 'install', '1', 'base', 'timezone' ) );

$db->query( sprintf( "DELETE FROM %s WHERE id = '%s'", $table, $id ) );
$db->query( sprintf(
    "INSERT INTO %s (id, scope_type, scope_id, module, name, value, autoload, creation_date)"
  . " VALUES ('%s', 'install', '1', 'base', 'timezone', '%s', 1, '0')",
    $table, $id, $db->prepare( serialize( 'Europe/London' ) ) ) );

$c->load( 1 );

printf( "PROBE effective=%s\n", owa_coreAPI::getSetting( 'base', 'timezone' ) );
printf( "PROBE constant=%s\n",  (string) $c->configFileConstantFor( 'base', 'timezone' ) );
printf( "PROBE persistable=%s\n", $c->mayPersistInstallWide( 'base', 'timezone' ) ? 'yes' : 'no' );

// A module declaring this key at module-load time, i.e. after the constants ran.
$c->registerField( 'base', 'timezone',
    array( 'default' => 'UTC', 'storable' => true, 'type' => 'select' ) );

printf( "PROBE persistable_after_late_registration=%s\n",
    $c->mayPersistInstallWide( 'base', 'timezone' ) ? 'yes' : 'no' );

$db->query( sprintf( "DELETE FROM %s WHERE id = '%s'", $table, $id ) );
