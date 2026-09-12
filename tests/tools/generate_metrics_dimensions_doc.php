<?php
/**
 * Emit the Metrics & Dimensions wiki tables from the registry.
 *
 *   php tests/tools/generate_metrics_dimensions_doc.php
 *
 * The page used to be maintained by hand and drifted: it documented a dimension
 * that did not exist, omitted eighteen that did, and named 33 of 78 metrics. The
 * registry is the only thing that knows, so the tables are generated from it and
 * the prose sections of the page stay hand-written.
 */

require __DIR__ . '/../../owa_env.php';
require __DIR__ . '/../../owa.php';

$owa = new owa( array( 'instance_role' => 'cli' ) );

function owa_doc_row( $name, $label, $desc, $extra ) {

    $desc = trim( (string) $desc );
    $desc = $desc === '' ? '—' : $desc;

    return sprintf( "|`%s` | %s | %s | %s |\n", $name, $label ?: '—', $desc, $extra ?: '—' );
}

$out = "## Metrics\n\n"
     . "|Name | Label | Description | Measured against |\n"
     . "|---|---|---|---|\n";

$metrics = (array) \OWA\Core\CoreAPI::getAllMetrics();
ksort( $metrics );

foreach ( $metrics as $name => $declarations ) {

    // A goalN metric exists once per numbered slot; listing 45 of them says
    // nothing the family line below does not.
    if ( preg_match( '/^goal\d+/', $name ) ) {

        continue;
    }

    $first = is_array( $declarations ) ? reset( $declarations ) : array();

    $entities = array();

    foreach ( (array) $declarations as $d ) {

        $e = $d['params']['entity'] ?? '';

        if ( $e ) {
            $entities[] = str_replace( 'base.', '', $e );
        }
    }

    $out .= owa_doc_row( $name, $first['label'] ?? '', $first['description'] ?? '',
        implode( ', ', array_unique( $entities ) ) );
}

$out .= "\nGoal metrics exist once per numbered goal slot — `goal1Completions`,\n"
      . "`goal1Starts`, `goal1Value` and so on, through goal 15. See\n"
      . "[[Conversion Tracking|Conversion-Tracking]].\n";

$out .= "\n## Dimensions\n\n"
      . "|Name | Label | Description | Family |\n"
      . "|---|---|---|---|\n";

$dims = (array) \OWA\Core\CoreAPI::getAllDimensions();
ksort( $dims );

foreach ( $dims as $name => $d ) {

    $out .= owa_doc_row( $name, $d['label'] ?? '', $d['description'] ?? '', $d['family'] ?? '' );
}

/*
 * Which dimensions can be asked for alongside which metrics.
 *
 * Derived the way the report builder derives it -- compatibleEntities() for a
 * metric, isDimensionRelated() for a dimension -- so the page states what the
 * query engine would actually accept. The previous hand-written table grouped
 * by family names that no longer exist ('technology', 'geography', 'traffic').
 */
$rsm = new \OWA\Module\Base\Classes\ResultSetManager;

$entities = array();

foreach ( array_keys( $metrics ) as $metric ) {

    foreach ( (array) $rsm->compatibleEntities( array( $metric ) ) as $e ) {
        $entities[ $e ] = true;
    }
}

$entities = array_keys( $entities );
sort( $entities );

$out .= "\n## Combinations\n\n"
      . "A metric can only be broken down by a dimension that its fact table is related\n"
      . "to. The families available against each fact table:\n\n"
      . "|Fact table | Dimension families |\n|---|---|\n";

foreach ( $entities as $entity ) {

    $fams = array();

    foreach ( $dims as $name => $d ) {

        if ( $rsm->isDimensionRelated( $name, $entity ) ) {

            $f = $d['family'] ?? '';

            if ( $f ) {
                $fams[ $f ] = true;
            }
        }
    }

    ksort( $fams );

    $out .= sprintf( "|`%s` | %s |\n", str_replace( 'base.', '', $entity ),
        $fams ? '`' . implode( '`, `', array_keys( $fams ) ) . '`' : '—' );
}

echo $out;
