<?php
/**
 * The site lists the channel rules test a source against, with every entry.
 *
 * Read through Classes\Cube\SiteLists, which owns the list names and their
 * files. The files are read straight from conf/ rather than through
 * SiteLists::entries(), which merges in an install's data-directory additions:
 * the page documents what ships.
 */

use OWA\Module\Base\Classes\Cube\SiteLists;

return function () {

    $out = "| List | File | Entries |\n|---|---|---|\n";

    foreach ( SiteLists::FILES as $list => $spec ) {

        $entries = array();

        foreach ( (array) include OWA_CONF_DIR . $spec[0] as $entry ) {

            $domain = strtolower( trim( (string) ( is_array( $entry ) ? ( $entry['domain'] ?? '' ) : $entry ) ) );

            if ( $domain === '' ) {
                continue;
            }

            // A search engine's query parameter is what search terms are read from.
            $param = is_array( $entry ) ? (string) ( $entry['query_param'] ?? '' ) : '';

            $entries[ $domain ] = '`' . $domain . '`' . ( $param !== '' ? " (`$param`)" : '' );
        }

        $out .= sprintf( "| `%s` | `conf/%s` | %s |\n", $list, $spec[0], implode( ', ', $entries ) );
    }

    return $out . "\nA search engine's search-term parameter is shown in brackets.\n";
};
