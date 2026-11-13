<?php
/**
 * What a =~ or !~ filter's pattern may not contain, and how long a report
 * query may run.
 *
 * Read from ResultSetManager::regexProblem()'s limits and QUERY_SECONDS, so the
 * page says what the code refuses.
 */

require_once __DIR__ . '/../lib/modules.php'; // owa_wiki_cell()

use OWA\Module\Base\Classes\ResultSetManager;

return function () {

    $rows = array(
        sprintf( 'Longer than %d characters', ResultSetManager::MAX_REGEX_LENGTH ),
        'Not a valid regular expression',
        'A backreference, such as `\\1`',
        'A repeated group that itself contains a repeat, such as `(a+)+` or `(\\w*)*`',
        sprintf( 'A repeat of more than %1$d, such as `{%2$d}`',
            ResultSetManager::MAX_REGEX_BOUND, ResultSetManager::MAX_REGEX_BOUND + 1 ),
        'Written with syntax MySQL does not run: `[[:<:]]` or `[[:>:]]` (use `\\b`), a branch reset `(?|`, '
            . '`\\K`, recursion such as `(?R)`, `(?1)` or `(?&name)`, a conditional `(?(`, a verb such as `(*FAIL)`, '
            . '`(?P` or `(?\'name\'` groups, `\\g` or `\\k` references, `\\C`, `\\N`, `\\o`, inline flags other '
            . 'than `i`, `m`, `s` and `x`, or an unescaped `[` or `&&` inside a character class',
    );

    $out = "| A pattern is refused when it is |\n|---|\n";

    foreach ( $rows as $row ) {

        $out .= '| ' . owa_wiki_cell( $row ) . " |\n";
    }

    $out .= sprintf( "\nA report query that runs for more than %d seconds is stopped.\n",
        ResultSetManager::QUERY_SECONDS );

    return $out;
};
