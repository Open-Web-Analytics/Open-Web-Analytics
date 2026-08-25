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
 * Goals live in the per-site `goals` setting, so this walks the sites.
 *
 * Both directions are idempotent, and both are pure functions the site-walk
 * applies -- `migrateGoals()` and `revertGoals()`. Re-running either is a
 * no-op: each moves a key only when the key it reads is actually present, and
 * the walk writes only when the transform returned something different, so a
 * second pass touches no rows at all. That matters more than usual here,
 * because the store is one serialized blob per site: a write that changes
 * nothing is still a write of the whole goals structure.
 *
 * NOT a read-side fallback anywhere: one shape in the store, decided here.
 */
class Update017 extends \OWA\Core\Update {

    var $schema_version = 17;

    var $is_cli_mode_required = false;

    /**
     * Rename one key WITHOUT moving it.
     *
     * The obvious spelling -- set the new key, unset the old -- appends, so the
     * renamed key lands at the end of the step and a rollback hands back a
     * structure that is not what it was given. Rebuilding in order keeps every
     * key where the operator put it, which is what makes down() an actual
     * reverse rather than merely value-equivalent.
     *
     * A step carrying BOTH keys keeps the destination one, at ITS position, and
     * simply drops the stale one.
     *
     * @param array<string, mixed> $step
     * @return array<string, mixed>
     */
    private static function renameKeyInPlace( array $step, string $from, string $to ): array {

        if ( ! array_key_exists( $from, $step ) ) {
            return $step;
        }

        $out = array();

        foreach ( $step as $key => $value ) {

            if ( $key !== $from ) {
                $out[ $key ] = $value;
                continue;
            }

            if ( ! array_key_exists( $to, $step ) ) {
                $out[ $to ] = $value;
            }
        }

        return $out;
    }

    /**
     * The rename itself, as a pure function.
     *
     * Public and separate so a test can exercise THE SHIPPED TRANSFORM. Running
     * up() writes the `goals` site setting, so a test that drove it would have
     * to write to a real install; a test that reimplemented it would pass while
     * this diverged.
     *
     * Idempotent: a step already carrying `path` keeps it, and a step carrying
     * both keeps `path` and drops the stale `url`. The key keeps its position,
     * so down( up( x ) ) is x exactly, not merely value-for-value.
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

                if ( ! is_array( $step ) ) {
                    continue;
                }

                $goals[ $gk ]['details']['funnel_steps'][ $sk ] =
                    self::renameKeyInPlace( $step, 'url', 'path' );
            }
        }

        return $goals;
    }

    /**
     * The reverse, on the same terms.
     *
     * Extracted for the reason `migrateGoals()` is: a down() that inlined its
     * transform could only be tested by asserting the method exists, which is
     * what this file used to settle for -- and an un-run rollback is the one
     * you find out about during a rollback.
     *
     * Idempotent: a step carrying only `path` moves it back; a step already
     * carrying `url` is left alone; a step carrying both keeps `url`. The key
     * goes back where it was, not onto the end.
     *
     * @param array<int|string, array> $goals the site's `goals` setting
     * @return array<int|string, array>
     */
    public static function revertGoals( array $goals ): array {

        foreach ( $goals as $gk => $goal ) {

            $steps = $goal['details']['funnel_steps'] ?? null;

            if ( ! is_array( $steps ) ) {
                continue;
            }

            foreach ( $steps as $sk => $step ) {

                if ( ! is_array( $step ) ) {
                    continue;
                }

                $goals[ $gk ]['details']['funnel_steps'][ $sk ] =
                    self::renameKeyInPlace( $step, 'path', 'url' );
            }
        }

        return $goals;
    }

    /**
     * Apply a transform to every site's goals, writing only what changed.
     *
     * Shared so up() and down() cannot drift into different definitions of
     * "nothing to do" -- the property that makes re-running either one safe.
     *
     * @param callable(array): array $transform
     */
    private function walkSites( callable $transform ): int {

        $changed = 0;

        foreach ( (array) \OWA\Core\CoreAPI::getSitesList() as $site ) {

            $siteId = is_array( $site ) ? ( $site['site_id'] ?? null ) : null;

            if ( ! $siteId ) {
                continue;
            }

            $goals = \OWA\Core\CoreAPI::getSiteSetting( $siteId, 'goals' );

            if ( ! is_array( $goals ) ) {
                continue;
            }

            $migrated = $transform( $goals );

            if ( $migrated !== $goals ) {
                \OWA\Core\CoreAPI::persistSiteSetting( $siteId, 'goals', $migrated );
                $changed++;
            }
        }

        return $changed;
    }

    function up( $force = false ) {

        $changed = $this->walkSites( array( self::class, 'migrateGoals' ) );

        $this->e->notice( sprintf(
            'Funnel steps now store their page under "path" (%d site(s) updated).', $changed ) );

        return true;
    }

    function down() {

        $changed = $this->walkSites( array( self::class, 'revertGoals' ) );

        $this->e->notice( sprintf(
            'Funnel steps store their page under "url" again (%d site(s) reverted).', $changed ) );

        return true;
    }
}
