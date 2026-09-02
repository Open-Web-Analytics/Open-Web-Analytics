<?php
/*
 * Answers ensureOrganization() in a FRESH process.
 *
 * The entity cache is per-process and getByColumn() populates it, so a test
 * that looks the Organization up, renames it, and looks again is answered from
 * the lookup it made BEFORE the rename -- which is exactly the masking that let
 * a name-keyed lookup ship. A new process is a new request, which is where the
 * bug actually bites.
 *
 * Prints the id on stdout, nothing else.
 */

require_once __DIR__ . '/../bootstrap_owa.php';

$sm = \OWA\Core\CoreAPI::supportClassFactory( 'base', 'siteManager' );

echo (string) $sm->ensureOrganization();
