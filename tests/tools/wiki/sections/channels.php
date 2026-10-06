<?php
/**
 * The shipped channel rules, in the order they are tested.
 *
 * Read through Classes\Cube\ChannelStep::definitions() -- the loader the build
 * uses, validation included -- pointed at conf/ so an install's own
 * data-directory file cannot stand in for the shipped one.
 */

use OWA\Module\Base\Classes\Cube\ChannelStep;

return function () {

    $code = fn ( $v ) => '`' . str_replace( '`', "'", (string) $v ) . '`';

    $verbs = array(
        'equals'      => 'is',
        'one_of'      => 'is one of',
        'contains'    => 'contains',
        'starts_with' => 'starts with',
        'ends_with'   => 'ends with',
        'regex'       => 'matches',
    );

    $render = function ( array $group ) use ( &$render, $code, $verbs ) {

        $mode  = isset( $group['all'] ) ? 'all' : 'any';
        $parts = array();

        foreach ( (array) $group[ $mode ] as $cond ) {

            if ( isset( $cond['any'] ) || isset( $cond['all'] ) ) {

                $parts[] = '(' . $render( $cond ) . ')';
                continue;
            }

            list( $field, $op, $value ) = $cond;

            if ( $op === 'in_list' ) {

                $parts[] = "$field is on the " . $code( $value ) . ' site list';
                continue;
            }

            $value = is_array( $value ) ? implode( ', ', array_map( $code, $value ) ) : $code( $value );

            $parts[] = $field . ' ' . ( $verbs[ $op ] ?? $op ) . ' ' . $value;
        }

        return count( $parts ) === 1 ? $parts[0] : implode( $mode === 'all' ? ' **and** ' : ' **or** ', $parts );
    };

    $out = "| Order | Channel | When |\n|---|---|---|\n";

    foreach ( ChannelStep::definitions( OWA_CONF_DIR ) as $i => $rule ) {

        $out .= sprintf( "| %d | %s | %s |\n", $i + 1, $rule['channel'],
            str_replace( '|', '\\|', $render( $rule ) ) );
    }

    $out .= sprintf( "| — | Unassigned | No rule matched |\n" );

    return $out;
};
