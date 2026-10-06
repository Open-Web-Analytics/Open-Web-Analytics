<?php
/**
 * The columns a reporting cube adds to raw's, and how a build fills each one.
 *
 * The derived set is the cube entity's columns less raw's; the definitions are
 * conf/cube_columns.php through the same loader Cube\Columns uses, and
 * Columns::steps() is asked to accept them, so a definition the build would
 * refuse fails here too rather than being documented.
 *
 * "Description" is the entry's `description`.
 *
 * "From" lists the logical inputs a definition names (`session.*`, `visitor.*`,
 * another cube column, or a compute class). "Unresolved when" names the test
 * that writes the unresolved sentinel instead of a value.
 */
return static function ( $arg = null ) {

    $cell = static fn ( $v ) => str_replace( array( '|', "\r", "\n" ), array( '\\|', ' ', ' ' ), trim( (string) $v ) );

    $raw  = new \OWA\Module\Base\Entity\EventRaw();
    $cube = new \OWA\Module\Base\Entity\Event();

    $derived = array_values( array_diff( $cube->getColumns(), $raw->getColumns() ) );

    $definitions = (array) \OWA\Core\CoreAPI::loadConf( 'cube_columns.php', 'cube.columns' );

    // Throws on a column with no definition or a definition with no column.
    ( new \OWA\Module\Base\Classes\Cube\Columns( $definitions ) )->steps( $derived );

    $inputs = static function ( $definition ) use ( &$inputs ) {

        $found = array();

        foreach ( (array) $definition as $key => $value ) {

            if ( in_array( $key, array( 'kind', 'text', 'absent', 'description' ), true ) ) {
                continue;
            }

            if ( is_array( $value ) ) {
                $found = array_merge( $found, $inputs( $value ) );
            } elseif ( is_string( $value ) && $value !== '' ) {
                $found[] = ltrim( $value, '\\' );
            }
        }

        return array_values( array_unique( $found ) );
    };

    $out = "| Column | Description | Kind | From | Type | Unresolved when |\n"
         . "|---|---|---|---|---|---|\n";

    foreach ( $derived as $name ) {

        $def  = (array) $definitions[ $name ];
        $col  = $cube->getColumn( $name );
        $from = $inputs( $def );

        $out .= sprintf( "| `%s` | %s | `%s` | %s | `%s`%s | %s |\n",
            $name,
            $cell( $def['description'] ?? '' ) ?: '—',
            $cell( $def['kind'] ?? '' ),
            $from ? '`' . implode( '`, `', array_map( $cell, $from ) ) . '`' : '—',
            $cell( $col->get( 'data_type' ) ),
            $col->get( 'is_not_null' ) ? ' NOT NULL' : '',
            isset( $def['absent'] ) ? '`' . $cell( $def['absent'] ) . '`' : '—' );
    }

    return $out;
};
