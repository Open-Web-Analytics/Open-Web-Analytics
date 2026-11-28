<?php

/*
 * Property fixtures: a domain of a fixture Property's own, and removing a
 * test Property with everything OWA keeps by its id.
 *
 * Kept out of bootstrap_owa.php so a test case that boots OWA its own way
 * (CliControllerTestCase, RestControllerTestCase) and the e2e seeders can
 * use them too.
 */

/**
 * A domain for a fixture Property, of its id: two live Properties may not
 * have one host (Entity\Property::LIVE_HOST_INDEX), so fixtures alive at the
 * same time each need their own.
 *
 * @param int|string $property_id
 */
function owa_test_property_domain($property_id): string
{
    return 'p' . $property_id . '.example.test';
}

/**
 * Remove a test Property: its Profiles, its row, and its cube with the
 * cube's build tables (owa_event_<id>, _rebuild, _computed). Anything
 * already gone is skipped.
 *
 * @param int|string $property_id
 */
function owa_test_remove_property($property_id): void
{
    // Entity::delete() refuses an empty id; there is nothing to remove for one.
    if ((string) $property_id === '' || (string) $property_id === '0') {
        return;
    }

    $db = \OWA\Core\CoreAPI::dbSingleton();

    \OWA\Core\CoreAPI::entityFactory('base.site')->delete((string) $property_id, 'property_id');
    \OWA\Core\CoreAPI::entityFactory('base.property')->delete((string) $property_id, 'id');

    $cube = \OWA\Module\Base\Classes\Cube\Cubes::tableFor($property_id);

    if ($cube !== '') {
        foreach (['', \OWA\Module\Base\Classes\Cube\Builder::STAGING_SUFFIX, \OWA\Module\Base\Classes\Cube\Builder::COMPUTED_SUFFIX] as $suffix) {
            $db->query(sprintf('DROP TABLE IF EXISTS %s%s', $cube, $suffix));
        }
    }

    \OWA\Core\CoreAPI::forgetRegisteredSites();
}

/**
 * The cubes whose Property has no row: what a test that removed a Property
 * without owa_test_remove_property() leaves.
 *
 * @return list<string> table names
 */
function owa_test_orphan_cubes(): array
{
    $db       = \OWA\Core\CoreAPI::dbSingleton();
    $property = \OWA\Core\CoreAPI::entityFactory('base.property')->getTableName();
    $orphans  = [];

    foreach (\OWA\Module\Base\Classes\Cube\Cubes::existing() as $id => $table) {
        if (!$db->get_row(sprintf('SELECT id FROM %s WHERE id = ?', $property), [(string) $id])) {
            $orphans[] = $table;
        }
    }

    return $orphans;
}
