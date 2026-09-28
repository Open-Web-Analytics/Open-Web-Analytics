<?php

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Copyright 2006 Peter Adams. All rights reserved.
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an "AS IS" BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.
//

/**
 * Backward-compatibility bridge for the PSR-4 namespace migration (Phase 6).
 * =========================================================================
 *
 * OWA's framework classes were renamed from the global-namespace `owa_`
 * prefix convention (owa_coreAPI, owa_entity, ...) into PSR-4 namespaces
 * (OWA\Core\CoreAPI, OWA\Core\Entity, ...). In v2 only the names a module
 * builds on keep resolving -- the base classes it extends and the static API
 * it calls -- plus owa_event for queued data. See owa_compat_class_map().
 *
 * HOW THE BRIDGE WORKS — a LAZY forward-alias autoloader.
 * -------------------------------------------------------
 * When any code (a factory, a third-party `new owa_document`, a `class_exists`
 * with autoload enabled) asks for a legacy `owa_*` name that has already been
 * migrated, this autoloader looks the name up in the old->new map, ensures the
 * new namespaced class is loaded (via Composer's PSR-4/classmap loader, which
 * owa_env.php registered immediately before requiring this file), and declares
 * a `class_alias(<new>, <old>)`. From that point the old name resolves to the
 * new class: `new owa_document` works, `instanceof` works in BOTH directions,
 * `extends owa_entity` works. (Verified with a standalone probe before this
 * file was written.)
 *
 * WHY LAZY, not eager. The original migration scoping assumed "no autoloader
 * today, so aliases must be declared eagerly at file-load." Phase-6 stage 0
 * falsified that: Composer's autoloader IS registered on every boot
 * (owa_env.php requires vendor/autoload.php) and a classmap over the whole tree
 * makes every class discoverable. A lazy autoloader therefore needs NO change
 * to the factory synthesis seam (owa_lib.php / owa_coreAPI factory $class_ns
 * defaults stay 'owa_') and costs nothing for names that are never requested.
 *
 * REGISTRATION ORDER. This autoloader is registered AFTER Composer's, so a
 * request for a new namespaced name resolves via Composer first; this bridge
 * only ever fires for a LEGACY `owa_*` name, which Composer cannot resolve
 * once the class has been renamed. The `owa_` prefix short-circuit keeps it a
 * no-op for every non-legacy class name.
 *
 * THE MAP. owa_compat_class_map() below is the whole list. OWA's own
 * factories do not read it for their own classes: each finds the namespaced
 * class by convention first, and reaches Lib::resolveNamespacedClass() only
 * for a legacy name a third party handed it. A `class_exists(<old>, false)`
 * guard in the alias step prevents redefining an old name that some code
 * still declares directly.
 *
 * RESIDUAL BREAK (documented, not worked around): a module doing string
 * equality on a class name — `get_class($x) === 'owa_foo'` or
 * `$x::class === 'owa_foo'` — sees the NEW name and breaks. That is rare, a
 * code smell versus `instanceof` (which is unaffected), and a one-line author
 * fix. It is covered by the migration wiki page, not by code here.
 */

/**
 * The legacy names v2 keeps: the classes a module EXTENDS, the static API it
 * CALLS, and one name stored DATA still carries.
 *
 * Everything else the namespace migration renamed -- services, handlers,
 * concrete validators, controllers, views, v1 updates, module registry
 * classes, owa_lib -- was an internal name, and v2 dropped it. OWA itself
 * resolves nothing through this map: its factories find namespaced classes
 * by convention (CompatMapIsNotLoadBearingTest).
 *
 * tests/fixtures/legacy_class_names.json is the same list, as the promise.
 *
 * @return array<string, string>
 */
function owa_compat_class_map(): array
{
    return [
        // --- what a module extends ---

        'owa_base' => 'OWA\\Core\\Base',
        'owa_module' => 'OWA\\Core\\Module',
        'owa_observer' => 'OWA\\Core\\Observer',
        'owa_update' => 'OWA\\Core\\Update',

        'owa_controller' => 'OWA\\Core\\Controller',
        'owa_adminController' => 'OWA\\Core\\AdminController',
        'owa_reportController' => 'OWA\\Core\\ReportController',
        'owa_cliController' => 'OWA\\Core\\Controller\\Cli',

        'owa_view' => 'OWA\\Core\\View',
        'owa_adminPageView' => 'OWA\\Core\\View\\AdminPage',
        'owa_restApiView' => 'OWA\\Core\\View\\RestApi',
        'owa_mailView' => 'OWA\\Core\\View\\Mail',
        'owa_cliView' => 'OWA\\Core\\View\\Cli',

        'owa_entity' => 'OWA\\Core\\Entity',
        'owa_factTable' => 'OWA\\Core\\Entity\\FactTable',
        'owa_metric' => 'OWA\\Core\\Metric',
        'owa_calculatedMetric' => 'OWA\\Core\\Metric\\CalculatedMetric',

        'owa_validation' => 'OWA\\Core\\Validation\\Validation',
        'owa_cacheType' => 'OWA\\Core\\CacheType',
        'owa_eventQueue' => 'OWA\\Core\\EventQueue',

        // --- what a module calls ---

        'owa_coreAPI' => 'OWA\\Core\\CoreAPI',

        // --- what stored data names ---

        /*
         * Queue items serialized before the migration name their event
         * owa_event, and unserialize() needs the name to exist
         * (EventQueue::allowedEventClasses()).
         */
        'owa_event' => 'OWA\\Module\\Base\\Classes\\Event',
    ];
}

// OWA_DISABLE_COMPAT_BRIDGE = true, defined before this file loads, turns the
// bridge off: no aliasing autoloader here, and Lib::resolveNamespacedClass()
// answers null. OWA runs fully that way (CompatMapIsNotLoadBearingTest).
// Default is ON -- the bridge is the third-party contract.
if (defined('OWA_DISABLE_COMPAT_BRIDGE') && OWA_DISABLE_COMPAT_BRIDGE) {
    return;
}

spl_autoload_register(function (string $class): void {

    // Only ever act on legacy global-namespace owa_* names. A namespaced name
    // (OWA\...) contains a backslash and is Composer's job, not ours.
    if (strncmp($class, 'owa_', 4) !== 0) {
        return;
    }

    $map = owa_compat_class_map();
    $new = $map[$class] ?? null;

    // PHP class names are case-insensitive, and legacy OWA code references some
    // owa_* names in the "wrong" case (e.g. deleteUserRestController.php extends
    // owa_usersdeleteController, whose canonical class is owa_usersDeleteController).
    // That resolved fine while the class was globally declared; now it lives only
    // behind this bridge, so fall back to a case-insensitive lookup to preserve
    // the original semantics.
    if ($new === null) {
        static $ciMap = null;
        if ($ciMap === null) {
            $ciMap = [];
            foreach ($map as $old => $target) {
                $ciMap[strtolower($old)] = $target;
            }
        }
        $new = $ciMap[strtolower($class)] ?? null;
    }

    if ($new === null) {
        return; // not a migrated class — leave it to the require_once loaders
    }

    // Make sure the new class is actually loaded (Composer PSR-4/classmap).
    // Guard the alias so we never redefine an old name that is still declared
    // directly somewhere during the transition.
    if (class_exists($new) && !class_exists($class, false)) {
        class_alias($new, $class);
    }
});
