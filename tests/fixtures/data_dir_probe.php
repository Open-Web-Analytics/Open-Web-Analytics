<?php

/**
 * Boots OWA from the CLI and prints, as one JSON line, where everything that
 * writes under the data directory resolved to, and every PHP warning raised on
 * the way (tests/DataDirConfigTest.php).
 *
 *   php -d auto_prepend_file=role_constants_prepend.php data_dir_probe.php [log]
 *
 * With "log", it also writes one error to OWA's log.
 */
$warnings = array();

set_error_handler( static function ( $no, $msg, $file, $line ) use ( &$warnings ) {

    $warnings[] = $msg . ' in ' . basename( (string) $file ) . ':' . $line;

    return true;
} );

$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0 Safari/537.36';

require dirname( __DIR__, 2 ) . '/owa.php';

$owa = new owa( array( 'request_type' => 'tracking' ) );

// OWA's debug mode installs a handler of its own; this probe's must see everything.
set_error_handler( static function ( $no, $msg, $file, $line ) use ( &$warnings ) {

    $warnings[] = $msg . ' in ' . basename( (string) $file ) . ':' . $line;

    return true;
} );

if ( in_array( 'log', $argv, true ) ) {

    \OWA\Core\CoreAPI::error( 'data dir probe' );
}

$queue    = new \OWA\Module\Base\Classes\FileEventQueue( array() );
$fallback = \OWA\Module\Base\Classes\TrackerIngest::fallback();
$cache    = new \OWA\Module\FileCache\Classes\FileCache( array() );

echo 'PROBE ' . json_encode( array(
    'data_dir'       => \OWA\Core\Env::dataDir(),
    'cache_dir'      => \OWA\Core\Env::cacheDir(),
    'error_log_file' => \OWA\Core\CoreAPI::getSetting( 'base', 'error_log_file' ),
    'async_log_dir'  => \OWA\Core\CoreAPI::getSetting( 'base', 'async_log_dir' ),
    'queue_dir'      => $queue->queue_dir,
    'archive_dir'    => $queue->archive_path,
    'dead_letter'    => $queue->deadLetterQueue()->queue_dir,
    'fallback_dir'   => $fallback ? $fallback->queue_dir : null,
    'file_cache_dir' => $cache->cache_dir,
    'maxmind_dir'    => \OWA\Module\MaxmindGeoip\Classes\Maxmind::dataDir(),
    'ua_regexes'     => \OWA\Module\Base\Classes\Browscap::regexesFile(),
    'constant'       => \OWA\Core\CoreAPI::configSingleton()->configFileConstantFor( 'base', 'data_dir' ),
    'warnings'       => $warnings,
) ) . "\n";
