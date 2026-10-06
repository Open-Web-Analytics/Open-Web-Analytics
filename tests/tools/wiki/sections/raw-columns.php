<?php
/**
 * owa_event_raw's columns, from the entity, beside the tracking properties
 * that fill each one (config/tracking_properties.json, through
 * TrackingEventHelpers -- the same reader ingest uses).
 *
 * "Description" is the column's own when the entity declares one, else the
 * description of the property that fills it.
 *
 * "Absent" is what the entity layer writes when the event carries no value:
 * Entity::writeValue() asked directly, so the page cannot disagree with it.
 */
return static function ( $arg = null ) {

    $cell = static fn ( $v ) => str_replace( array( '|', "\r", "\n" ), array( '\\|', ' ', ' ' ), trim( (string) $v ) );

    $raw  = new \OWA\Module\Base\Entity\EventRaw();
    $cube = new \OWA\Module\Base\Entity\Event();

    $in_cube = array_flip( $cube->getColumns() );

    $write = new ReflectionMethod( $raw, 'writeValue' );
    $write->setAccessible( true );

    $helpers = '\OWA\Module\Base\Classes\TrackingEventHelpers';
    $filled  = array();
    $about   = array();

    foreach ( $helpers::allProperties() as $property => $definition ) {

        $column = $helpers::columnFor( $property );

        $label  = '`' . $property . '` (' . ( $definition['set_by'] ?? '?' ) . ')';

        if ( $column !== '' ) {
            $filled[ $column ][] = $label;
            $about[ $column ][]  = $helpers::descriptionFor( $property );
        } elseif ( $helpers::paramFor( $property ) !== '' ) {
            // Not a column of its own: a key inside the `params` document.
            $filled['params'][] = $label;
        }
    }

    $out = "| Column | Description | Type | Absent | Filled from | In cube |\n"
         . "|---|---|---|---|---|---|\n";

    foreach ( $raw->getColumns() as $name ) {

        $col = $raw->getColumn( $name );

        $v       = $write->invoke( $raw, $name );
        $default = $col->get( 'default_value' );

        if ( $col->get( 'is_primary_key' ) ) {
            $absent = 'key';
        } elseif ( $v === null && $default !== null ) {
            $absent = '`' . var_export( $default, true ) . '` (default)';
        } elseif ( $v === null && $col->get( 'is_not_null' ) ) {
            $absent = 'refused';
        } else {
            $absent = $v === null ? '`NULL`' : '`' . var_export( $v, true ) . '`';
        }

        $from = $filled[ $name ] ?? array();

        $description = (string) $col->get( 'description' );

        if ( $description === '' ) {
            $description = implode( ' ', array_unique( array_filter( $about[ $name ] ?? array() ) ) );
        }

        $out .= sprintf( "| `%s` | %s | `%s` | %s | %s | %s |\n",
            $name,
            $cell( $description ) ?: '—',
            $cell( $col->get( 'data_type' ) ),
            $absent,
            $from ? implode( ', ', $from ) : '—',
            isset( $in_cube[ $name ] ) ? 'yes' : 'no' );
    }

    return $out;
};
