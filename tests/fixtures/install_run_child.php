<?php
/**
 * InstallRunTest's child: Install::run(), which exits, with updater.php's
 * closures replaced by ones that do what the plan says, and OWA's settings
 * and log by stand-ins, so nothing boots. Each closure called is a JSON line
 * on STDOUT; anything else run() prints is passed through.
 *
 *   php install_run_child.php <root> '<plan as JSON>'
 *
 * The plan: marker_fails (phases whose marker write throws), alone (false:
 * other runs did not end), apply_fails ('to', 'from'), load (apply tries to
 * load a class), quiet.
 */

require dirname( __DIR__, 2 ) . '/vendor/autoload.php';

use OWA\Core\CoreAPI;
use OWA\Module\Base\Classes\AutoUpdate\Install;

$root = $argv[1];
$plan = (array) json_decode( $argv[2], true );
$say  = static function ( ...$call ) {
    fwrite( STDOUT, json_encode( $call ) . "\n" );
};

// run() saves the settings first, and logs a notice when it gives up.
( new ReflectionProperty( CoreAPI::class, 'config' ) )->setValue( null, new class {

    public function saveIfDirty() {}
} );

( new ReflectionProperty( CoreAPI::class, 'error' ) )->setValue( null, new class( $say ) {

    public function __construct( private $say ) {}

    public function notice( $msg ) {
        ( $this->say )( 'notice', $msg );
    }

    public function debug( $msg, $context = null ) {}
} );

$u = [
    // The real one: plain PHP, nothing to stand in for.
    'logged'      => ( require dirname( __DIR__, 2 ) . '/updater.php' )['logged'],
    'writeMarker' => static function ( $root, array $marker ) use ( $say, $plan ) {

        $say( 'writeMarker', $marker['phase'] );

        if ( in_array( $marker['phase'], (array) ( $plan['marker_fails'] ?? [] ), true ) ) {
            throw new RuntimeException( "The marker cannot be written ({$marker['phase']})." );
        }

        file_put_contents( "$root/owa-update.php", json_encode( $marker ) );
    },
    'marker'      => static fn ( $root ) => "$root/owa-update.php",
    'alone'       => static function ( $running, $wait ) use ( $say, $plan ) {

        $say( 'alone', $wait );

        return $plan['alone'] ?? true;
    },
    'apply'       => static function ( $root, $h, $keep ) use ( $say, $plan ) {

        $say( 'apply', $h );

        if ( ! empty( $plan['load'] ) ) {

            try {
                class_exists( 'OWA\\Module\\Base\\Classes\\AutoUpdate\\Vcs' );
                $say( 'loaded' );
            } catch ( LogicException $e ) {
                $say( 'refused', $e->getMessage() );
            }
        }

        if ( in_array( $h, (array) ( $plan['apply_fails'] ?? [] ), true ) ) {
            throw new RuntimeException( "Could not write Core/Env.php ($h)." );
        }
    },
    'recordFailure' => static function ( $updates, array $marker, $error ) use ( $say ) {
        $say( 'recordFailure', $updates, $marker['phase'], $error );
    },
    'unlock'      => static function ( array $hold ) use ( $say ) {
        $say( 'unlock', $hold['file'] );
    },
];

Install::$pending = [
    'root'       => $root,
    'from'       => '2.0.1',
    'to'         => '2.0.2',
    'to_h'       => 'to',
    'from_h'     => 'from',
    'to_files'   => [],
    'from_files' => [],
    'hold'       => [ 'file' => "$root/owa-data/updating" ],
    'updates'    => "$root/owa-data/updates-test",
    'from_tar'   => "$root/owa-data/updates-test/owa_2.0.1_packaged.tar",
    'to_tar'     => "$root/owa-data/updates-test/owa_2.0.2_packaged.tar",
    'from_sha'   => str_repeat( 'a', 64 ),
    'to_sha'     => str_repeat( 'b', 64 ),
    'quiet'      => ! empty( $plan['quiet'] ),
    'u'          => $u,
    'log'        => $u['logged']( [], 'Release check (by hand), OWA 2.0.1 installed.' )['log'],
];

Install::run( null );

$say( 'returned' );
