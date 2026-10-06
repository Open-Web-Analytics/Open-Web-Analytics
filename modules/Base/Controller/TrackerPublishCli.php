<?php

namespace OWA\Module\Base\Controller;

/**
 * Publish Profiles' tracking bundles (PLAN 2.24.5).
 *
 *   cli.php cmd=publish-trackers               every bundle that is not current
 *   cli.php cmd=publish-trackers force=1       every bundle
 *   cli.php cmd=publish-trackers site=<id>     one Profile's
 *
 * What starts it (PLAN 2.30.7): a Profile's save publishes that Profile at
 * once; a change above one Profile queues one run of this on the job queue;
 * cmd=update republishes what a new release changed. Run it by hand after
 * anything none of those see -- a config-file constant such as
 * OWA_PUBLIC_URL, a rebuilt tracker on a development checkout. A bundle is
 * current when its first line matches what it would be written with now, so
 * a run with nothing to do reads one line per Profile.
 *
 * After anything is published, and otherwise once a day, it fetches one bundle
 * and reads back the cache header it is served with.
 */
class TrackerPublishCli extends \OWA\Core\Controller\Cli {

    /** See \OWA\Core\Controller\Cli::usage(). */
    public static function usage() {

        return array(
            'description' => 'Publishes the Profiles\' tracking bundles that are not current. Saves and cmd=update publish on their own; run it after a change neither sees, such as a config-file constant.',
            'arguments'   => array(
                'force=1'   => 'Publish every bundle, current or not.',
                'site=<id>' => 'Publish only this Profile\'s bundle.',
            ),
        );
    }

    /** How old the cache header check may get before a quiet run repeats it. */
    const CHECK_EVERY = 86400;

    function action() {

        $B = '\OWA\Module\Base\Classes\TrackerBundle';

        $site = (string) $this->getParam( 'site' );

        $results = $B::publishStale( (bool) $this->getParam( 'force' ), $site !== '' ? array( $site ) : null );

        $counts = array_count_values( $results );

        $this->e->notice( sprintf( 'Tracker bundles: %d published, %d current, %d failed, %d removed.',
            $counts['published'] ?? 0, $counts['current'] ?? 0, $counts['failed'] ?? 0, $counts['removed'] ?? 0 ) );

        foreach ( $results as $id => $state ) {

            if ( $state === 'failed' ) {

                $this->e->notice( "Tracker bundle for $id was not published; see the log." );
            }
        }

        $last = (array) \OWA\Core\CoreAPI::getSetting( 'base', 'tracker_cache_headers' );
        $sample = array_search( 'published', $results, true );

        if ( $sample === false && time() - (int) ( $last['checked_at'] ?? 0 ) >= self::CHECK_EVERY ) {

            $sample = array_key_first( array_filter( $results, fn ( $s ) => $s === 'current' ) );
        }

        if ( $sample !== false && $sample !== null ) {

            $check = $B::checkCacheHeaders( (string) $sample );

            $this->e->notice( $check['ok'] === null
                ? sprintf( 'Tracker bundle cache check: %s answered %s, so the header visitors get is unknown.',
                    $check['url'], $check['status'] ? 'HTTP ' . $check['status'] : 'nothing' )
                : sprintf( 'Tracker bundle cache check: Cache-Control "%s" (%s).',
                    $check['cache_control'], $check['ok'] ? 'revalidated on every page view' : 'not set to revalidate' ) );
        }
    }
}

?>
