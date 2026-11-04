<?php
/**
 * The operators a constraint is written with, and the empty tests.
 *
 * Read from ResultSetManager::CONSTRAINT_OPERATORS, the list a constraint
 * string is parsed with, in the order it is tested; and from
 * ConfiguredReport::EMPTY_TESTS, the `(not set)` forms and the operator a
 * report definition's array form writes each as.
 */

use OWA\Core\ConfiguredReport;
use OWA\Module\Base\Classes\ResultSetManager;

return function () {

    $out = "| Operator | Meaning |\n|---|---|\n";

    foreach ( ResultSetManager::CONSTRAINT_OPERATORS as $operator => $meaning ) {
        $out .= sprintf( "| `%s` | %s |\n", $operator, $meaning );
    }

    foreach ( ConfiguredReport::EMPTY_TESTS as $name => $form ) {

        $operator = substr( $form, 0, - strlen( ResultSetManager::NOT_SET_LABEL ) );

        $out .= sprintf( "| `%s` | %s empty. A report definition's array form writes it as the operator `%s`. |\n",
            str_replace( '|', '\\|', $form ), ResultSetManager::CONSTRAINT_OPERATORS[ $operator ] ?? $operator, $name );
    }

    return $out;
};
