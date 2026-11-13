<?php
/**
 * What a =~ or !~ filter's pattern may not contain, and how long a report
 * query may run.
 *
 * Read from ResultSetManager::regexProblem()'s limits and QUERY_SECONDS, so the
 * page says what the code refuses.
 */

use OWA\Module\Base\Classes\ResultSetManager;

return function () {

    $rows = array(
        sprintf( 'Longer than %d characters', ResultSetManager::MAX_REGEX_LENGTH ),
        'Not a valid regular expression',
        'A backreference, such as `\\1`',
        'A repeated group that itself contains a repeat, such as `(a+)+` or `(\\w*)*`',
        sprintf( 'A repeat of more than %1$d, such as `{%2$d}`',
            ResultSetManager::MAX_REGEX_BOUND, ResultSetManager::MAX_REGEX_BOUND + 1 ),
    );

    $out = "| A pattern is refused when it is |\n|---|\n";

    foreach ( $rows as $row ) {

        $out .= '| ' . $row . " |\n";
    }

    $out .= sprintf( "\nA report query that runs for more than %d seconds is stopped.\n",
        ResultSetManager::QUERY_SECONDS );

    return $out;
};
