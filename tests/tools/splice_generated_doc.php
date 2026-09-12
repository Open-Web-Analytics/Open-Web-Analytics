<?php
/**
 * Replace the generated section of a wiki page, leaving the rest alone.
 *
 *   php tests/tools/splice_generated_doc.php <page.md> <generated.md>
 *
 * The page is part generated and part written by hand: the metric, dimension and
 * combination tables come from the registry, and the notes after them do not.
 * Overwriting the file wholesale would throw the second kind away, so the
 * generated part is delimited and only that is replaced.
 *
 * Refuses rather than guesses if the markers are missing, because the failure
 * mode of guessing is deleting prose nobody has a copy of.
 */

const BEGIN = '<!-- BEGIN GENERATED -->';
const END   = '<!-- END GENERATED -->';

$page_path = $argv[1] ?? '';
$gen_path  = $argv[2] ?? '';

if ( ! $page_path || ! $gen_path ) {

    fwrite( STDERR, "usage: splice_generated_doc.php <page.md> <generated.md>\n" );
    exit( 2 );
}

foreach ( array( $page_path, $gen_path ) as $p ) {

    if ( ! is_readable( $p ) ) {

        fwrite( STDERR, "cannot read $p\n" );
        exit( 2 );
    }
}

$page = file_get_contents( $page_path );
$gen  = rtrim( file_get_contents( $gen_path ) );

$start = strpos( $page, BEGIN );
$end   = strpos( $page, END );

if ( $start === false || $end === false || $end < $start ) {

    fwrite( STDERR, sprintf(
        "%s has no %s / %s markers, so there is no way to tell what may be replaced.\n"
      . "Add them around the generated tables and run this again.\n",
        $page_path, BEGIN, END ) );
    exit( 1 );
}

$new = substr( $page, 0, $start ) . BEGIN . "\n\n" . $gen . "\n\n" . substr( $page, $end );

if ( $new === $page ) {

    echo "unchanged\n";
    exit( 0 );
}

file_put_contents( $page_path, $new );

echo "updated\n";
