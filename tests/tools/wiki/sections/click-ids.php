<?php
/**
 * The ad networks' click ids OWA reads when a landing URL carries no source,
 * medium or campaign tag, and the source and medium each stands for, from
 * TrackingEventHelpers::CLICK_IDS. Ids that stand for the same pair share a row.
 */

use OWA\Module\Base\Classes\TrackingEventHelpers;

return function () {

    $rows = array(); // source|medium => params

    foreach ( TrackingEventHelpers::CLICK_IDS as $param => list( $source, $medium ) ) {
        $rows[ $source . '|' . $medium ][] = $param;
    }

    $out = "| Parameter | Source | Medium |\n|---|---|---|\n";

    foreach ( $rows as $pair => $params ) {

        list( $source, $medium ) = explode( '|', $pair, 2 );

        $out .= sprintf( "| `%s` | `%s` | `%s` |\n", implode( '`, `', $params ), $source, $medium );
    }

    return $out;
};
