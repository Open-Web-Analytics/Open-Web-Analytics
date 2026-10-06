<?php
/**
 * Every registered metric, grouped as the report builder groups them.
 *
 * "Computed as" is rendered from the definition's own kind and inputs -- the
 * same fields Base\Metric\ConfigurableMetric reads -- so it states what the
 * query engine does rather than what someone remembered it doing.
 */
return static function ( $arg = null ) {

    $cell = static fn ( $v ) => str_replace( array( '|', "\r", "\n" ), array( '\\|', ' ', ' ' ), trim( (string) $v ) );
    $code = static fn ( $v ) => '`' . $cell( $v ) . '`';

    $where = static function ( array $p ) use ( $code ) {

        $c = $p['condition'] ?? array();

        if ( ! $c || ! isset( $c['column'] ) ) {
            return '';
        }

        return ' where ' . $code( $c['column'] ) . ' = ' . $code( (string) $c['value'] );
    };

    $computed = static function ( array $p ) use ( $code, $where ) {

        switch ( $p['metric_type'] ?? '' ) {

            case 'count':
                return 'Count of events' . $where( $p );

            case 'distinct_count':
                return 'Distinct ' . $code( $p['column'] ) . $where( $p );

            case 'sum':
                return 'Sum of ' . $code( $p['column'] ) . $where( $p );

            case 'ratio':
                return $code( $p['numerator'] ) . ' ÷ ' . $code( $p['denominator'] );

            case 'difference':
                return $code( $p['minuend'] ) . ' − ' . $code( $p['subtrahend'] );
        }

        // A metric class of its own rather than a configured one.
        return isset( $p['metric_type'] ) && $p['metric_type'] !== '' ? $code( $p['metric_type'] ) : '—';
    };

    $groups = array();

    foreach ( (array) \OWA\Core\CoreAPI::getAllMetrics() as $name => $declarations ) {

        $first  = is_array( $declarations ) ? reset( $declarations ) : array();
        $params = (array) ( $first['params'] ?? array() );
        $group  = (string) ( $first['group'] ?? $params['group'] ?? '' );

        $groups[ $group !== '' ? $group : 'Other' ][ $name ] = array(
            'label'       => $first['label'] ?? $params['label'] ?? '',
            'description' => $first['description'] ?? $params['description'] ?? '',
            'type'        => $params['data_type'] ?? '',
            'computed'    => $computed( $params ),
        );
    }

    ksort( $groups );

    $out = '';

    foreach ( $groups as $group => $metrics ) {

        ksort( $metrics );

        $out .= "### " . $group . "\n\n"
              . "| Name | Label | Description | Computed as | Type |\n"
              . "|---|---|---|---|---|\n";

        foreach ( $metrics as $name => $m ) {

            $out .= sprintf( "| `%s` | %s | %s | %s | %s |\n",
                $name,
                $cell( $m['label'] ) ?: '—',
                $cell( $m['description'] ) ?: '—',
                $m['computed'],
                $m['type'] !== '' ? $code( $m['type'] ) : '—' );
        }

        $out .= "\n";
    }

    return $out;
};
