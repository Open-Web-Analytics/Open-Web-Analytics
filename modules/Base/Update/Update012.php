<?php

namespace OWA\Module\Base\Update;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * Repair persisted configuration that has drifted from the code defaults.
 *
 * TWO problems, both caused by the same historic behaviour: an old config GUI
 * persisted the WHOLE settings array rather than only the fields a form
 * changed. A stored value overrides the code default forever, so every one of
 * those redundant copies silently pinned a default -- and went wrong the moment
 * that default changed.
 *
 * 1. DANGLING .tpl TEMPLATE NAMES. Installs predating the .tpl -> .php template
 *    migration have template settings still naming a .tpl file. No .tpl file
 *    exists any more, so Template::set_template() finds nothing, returns false,
 *    and leaves the template path empty -- the eventual include('') raises
 *    "ValueError: Path cannot be empty" and the request 500s. The only clue is
 *    written to OWA's own log ("<name> was not found in any template
 *    directory"), never to the web server's, which is why an affected install
 *    can sit broken without an obvious cause.
 *
 * 2. REDUNDANT COPIES OF DEFAULTS. Everything else that duplicates the current
 *    default is removed, so those settings track the code again. This is
 *    behaviour-preserving by definition: get() falls back to the very default
 *    the stored value duplicated.
 *
 * Settings::persistSetting() no longer creates either condition, so this update
 * is a one-off repair of installs that already have them.
 */
class Update012 extends \OWA\Core\Update {

    var $schema_version = 12;

    function up($force = true) {

        $config = $this->c;

        $result = self::repair( $config );

        if ( $result['retargeted'] ) {
            \OWA\Core\CoreAPI::notice(
                sprintf( 'Retargeted %d stale .tpl setting(s): %s',
                    count( $result['retargeted'] ), implode( ', ', $result['retargeted'] ) )
            );
        }

        if ( $result['removed'] ) {
            \OWA\Core\CoreAPI::notice(
                sprintf( 'Removed %d persisted setting(s) that duplicated the code default: %s',
                    count( $result['removed'] ), implode( ', ', $result['removed'] ) )
            );
        }

        if ( ! $result['retargeted'] && ! $result['removed'] ) {
            \OWA\Core\CoreAPI::notice(
                'No redundant or dangling settings found. (Config-file-only '
                . 'settings, if any were stored, are dropped by Settings::load() '
                . 'and will not be written back.)'
            );
            return true;
        }

        return (bool) $config->save();
    }

    /**
     * The repair itself, separated from up() so it can be exercised without
     * triggering a save() -- these tests drive the shared config singleton, and
     * a save writes the real owa_configuration row.
     *
     * Mutates $config->db_settings in place; the caller decides whether to
     * persist.
     *
     * @param  object $config the Settings instance
     * @return array{retargeted: string[], removed: string[]}
     */
    public static function repair( $config ) {

        // NOTE on config-file-only settings (async_log_dir, report_wrapper,
        // error_log_file, db_*, the *_dir paths ...): this update does NOT
        // handle them, deliberately. Settings::load() drops them from
        // db_settings on every request, so by the time any update runs they are
        // already gone from the in-memory set, and up()'s save() rewrites the
        // row without them. Repeating the removal here would be dead code that
        // never fires and reports removals that already happened.
        //
        // That is also why the fix does not depend on this update: an affected
        // install self-heals on its next request. Update012 only handles what
        // load() deliberately leaves alone.

        // ---- 1. dangling .tpl names -------------------------------------
        // Any persisted string still naming a .tpl file is dangling: the
        // migration removed every .tpl from the tree. Rewrite to .php, which
        // is what the file was renamed to.
        $retargeted = array();

        foreach ( $config->db_settings as $module => $values ) {

            if ( ! is_array( $values ) ) {
                continue;
            }

            foreach ( $values as $key => $value ) {

                if ( is_string( $value ) && substr( $value, -4 ) === '.tpl' ) {

                    $new = substr( $value, 0, -4 ) . '.php';
                    $config->persistSetting( $module, $key, $new );
                    $retargeted[] = sprintf( '%s.%s (%s -> %s)', $module, $key, $value, $new );
                }
            }
        }

        // ---- 2. redundant copies of code defaults ------------------------
        $removed = $config->pruneRedundantPersistedSettings();

        return array( 'retargeted' => $retargeted, 'removed' => $removed );
    }

    /**
     * Not reversible, and deliberately so. Going "back" would mean re-writing
     * values identical to the defaults -- restoring the very latent bug this
     * update exists to clear. The post-update state is behaviourally identical
     * to the pre-update state, so there is nothing to undo.
     */
    function down() {

        \OWA\Core\CoreAPI::notice(
            'Update012 is not reversible: it only removed configuration that '
            . 'duplicated the code defaults, which changes no behaviour.'
        );

        return true;
    }
}
