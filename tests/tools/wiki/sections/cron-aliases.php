<?php
/**
 * The `@` shorthands a schedule may use: Core\Cron::ALIASES, with
 * Cron::describe() for the words. Aliases that expand to the same expression
 * share a row.
 */
return static function () {

    $rows = array(); // expression => aliases

    foreach ( \OWA\Core\Cron::ALIASES as $alias => $expr ) {
        $rows[ $expr ][] = $alias;
    }

    $out = "| Alias | Expression | In words |\n|---|---|---|\n";

    foreach ( $rows as $expr => $aliases ) {

        $out .= sprintf( "| %s | `%s` | %s |\n",
            '`' . implode( '` / `', $aliases ) . '`',
            $expr,
            \OWA\Core\Cron::describe( $aliases[0] ) );
    }

    return $out;
};
