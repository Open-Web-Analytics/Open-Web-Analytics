<?php
/**
 * Every registered dimension, grouped by family.
 *
 * "Reads" is the cube column for a plain column read, and "computed" for an
 * expression (a date part, a concatenation): the expression is SQL with a
 * table-alias placeholder, which is not something a reader can use.
 */
return static function ( $arg = null ) {

    $cell = static fn ( $v ) => str_replace( array( '|', "\r", "\n" ), array( '\\|', ' ', ' ' ), trim( (string) $v ) );

    $families = array();

    foreach ( (array) \OWA\Core\CoreAPI::getAllDimensions() as $name => $d ) {

        $family = (string) ( $d['family'] ?? '' );

        $families[ $family !== '' ? $family : 'other' ][ $name ] = $d;
    }

    ksort( $families );

    $out = '';

    foreach ( $families as $family => $dims ) {

        ksort( $dims );

        $out .= '### ' . ucfirst( $family ) . "\n\n"
              . "| Name | Label | Description | Reads | Type |\n"
              . "|---|---|---|---|---|\n";

        foreach ( $dims as $name => $d ) {

            $reads = ! empty( $d['expression'] ) ? 'computed' : '`' . $cell( $d['column'] ?? '' ) . '`';

            $out .= sprintf( "| `%s` | %s | %s | %s | %s |\n",
                $name,
                $cell( $d['label'] ?? '' ) ?: '—',
                $cell( $d['description'] ?? '' ) ?: '—',
                $reads,
                ! empty( $d['data_type'] ) ? '`' . $cell( $d['data_type'] ) . '`' : '—' );
        }

        $out .= "\n";
    }

    return $out;
};
