<?php

namespace OWA\Module\Base\Update;

/**
 * A funnel step's page is stored under `path`, not `url`.
 *
 * It was always a path: the funnel report constrains `pagePath == $step[...]`,
 * and checkGoalStart matches it against the event's `page_uri`. Only the entry
 * form called it a URL, which invited a value none of those three could match --
 * a funnel that reports zero and a goal that never starts, with nothing logged.
 *
 * The label now says Path. Renaming the key too is what stops the next reader
 * having to know the label ever lied.
 *
 * Goals live in the per-site `goals` setting, so this walks the sites. Steps
 * that already carry `path` are left alone, which makes the update safe to run
 * twice; a step carrying both keeps `path` and drops the stale `url`.
 *
 * NOT a read-side fallback anywhere: one shape in the store, decided here.
 */
class Update017 extends \OWA\Core\Update {

    var $schema_version = 17;

    var $is_cli_mode_required = false;

    /**
     * The rename itself, as a pure function.
     *
     * Public and separate so a test can exercise THE SHIPPED TRANSFORM. Running
     * up() writes the `goals` site setting, so a test that drove it would have
     * to write to a real install; a test that reimplemented it would pass while
     * this diverged.
     *
     * Idempotent: a step already carrying `path` keeps it, and a step carrying
     * both keeps `path` and drops the stale `url`.
     *
     * @param array<int|string, array> $goals the site's `goals` setting
     * @return array<int|string, array>
     */
    public static function migrateGoals( array $goals ): array {

        foreach ( $goals as $gk => $goal ) {

            $steps = $goal['details']['funnel_steps'] ?? null;

            if ( ! is_array( $steps ) ) {
                continue;
            }

            foreach ( $steps as $sk => $step ) {

                if ( ! is_array( $step ) || ! array_key_exists( 'url', $step ) ) {
                    continue;
                }

                if ( ! array_key_exists( 'path', $step ) ) {
                    $goals[ $gk ]['details']['funnel_steps'][ $sk ]['path'] = $step['url'];
                }

                unset( $goals[ $gk ]['details']['funnel_steps'][ $sk ]['url'] );
            }
        }

        return $goals;
    }

    function up( $force = false ) {

        $sites   = (array) \OWA\Core\CoreAPI::getSitesList();
        $changed = 0;

        foreach ( $sites as $site ) {

            $siteId = is_array( $site ) ? ( $site['site_id'] ?? null ) : null;

            if ( ! $siteId ) {
                continue;
            }

            $goals = \OWA\Core\CoreAPI::getSiteSetting( $siteId, 'goals' );

            if ( ! is_array( $goals ) ) {
                continue;
            }

            $migrated = self::migrateGoals( $goals );

            if ( $migrated !== $goals ) {
                \OWA\Core\CoreAPI::persistSiteSetting( $siteId, 'goals', $migrated );
                $changed++;
            }
        }

        $this->e->notice( sprintf(
            'Funnel steps now store their page under "path" (%d site(s) updated).', $changed ) );

        return true;
    }

    function down() {

        $sites = (array) \OWA\Core\CoreAPI::getSitesList();

        foreach ( $sites as $site ) {

            $siteId = is_array( $site ) ? ( $site['site_id'] ?? null ) : null;

            if ( ! $siteId ) {
                continue;
            }

            $goals = \OWA\Core\CoreAPI::getSiteSetting( $siteId, 'goals' );

            if ( ! is_array( $goals ) ) {
                continue;
            }

            $dirty = false;

            foreach ( $goals as $gk => $goal ) {

                foreach ( (array) ( $goal['details']['funnel_steps'] ?? array() ) as $sk => $step ) {

                    if ( is_array( $step ) && array_key_exists( 'path', $step ) ) {

                        $goals[ $gk ]['details']['funnel_steps'][ $sk ]['url'] = $step['path'];
                        unset( $goals[ $gk ]['details']['funnel_steps'][ $sk ]['path'] );
                        $dirty = true;
                    }
                }
            }

            if ( $dirty ) {
                \OWA\Core\CoreAPI::persistSiteSetting( $siteId, 'goals', $goals );
            }
        }

        return true;
    }
}
