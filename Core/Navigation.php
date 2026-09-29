<?php
namespace OWA\Core;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * One way every navigation list in the UI is finished: filtered, then gated.
 *
 * A module adds, removes or reorders entries by registering for the list's
 * filter -- nav_header, nav_user_menu, nav_help_menu, nav_site_control. The
 * filter receives the list as built and any context the surface passes, and
 * returns the list. An entry is an array; one with a `capability` is kept only
 * for a user who has it, and that check runs AFTER the filter, so a module's
 * entries are gated the same way as the framework's.
 *
 * Lists built by the registries (the report nav, the settings sidebar,
 * view-scoped navs, the hierarchy nav) are filtered where they are assembled;
 * this class is for the lists templates build.
 */
final class Navigation {

    /**
     * @param  string $filter  the list's filter name
     * @param  array  $items   the list as the framework builds it
     * @param  mixed  ...$context passed to every filter after the list
     * @return array  the entries to render, in order
     */
    public static function links( $filter, array $items, ...$context ) {

        $items = \OWA\Core\CoreAPI::filter( $filter, $items, ...$context );
        $user  = \OWA\Core\CoreAPI::getCurrentUser();
        $out   = array();

        foreach ( (array) $items as $item ) {

            if ( ! is_array( $item ) || empty( $item['href'] ) ) {

                continue;
            }

            if ( ! empty( $item['capability'] ) && ! $user->isCapable( $item['capability'] ) ) {

                continue;
            }

            $out[] = $item;
        }

        return $out;
    }
}

?>
