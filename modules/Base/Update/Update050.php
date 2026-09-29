<?php

namespace OWA\Module\Base\Update;

/**
 * A purchase gets its order id, its tax and its shipping.
 *
 * `revenue` and `currency` were the only two things a purchase stored. The rest
 * of the transaction -- what it was, what the tax was, what the delivery cost --
 * reached the row builder and was dropped: the per-event param list named
 * `transaction_id, tax, shipping, gateway, items` and the wire sends
 * `ct_order_id, ct_tax, ct_shipping, ct_gateway, ct_line_items`, so every lookup
 * missed and `params` came back NULL. Measured on a purchase carrying all five.
 *
 * WHY THESE THREE ARE COLUMNS AND THE OTHERS ARE PARAMS. Tax and shipping are
 * summed metrics -- taxRevenue and shippingRevenue -- and a metric needs a
 * column to sum; summing a value out of a JSON document means a generated column
 * to index, which is a column with extra steps. transaction_id is what makes a
 * purchase countable once and what a line item groups back to. The gateway and
 * the order source are labels nobody adds up, so they stay params.
 *
 * MINOR UNITS, like revenue, because a float column sums to something nobody can
 * reconcile. V2Event::minorUnits() rounds before casting.
 *
 * NOT CLI-ONLY. Three columns on raw and the cube, as any release adding one.
 */
class Update050 extends \OWA\Core\Update {

    use CubeColumn;

    var $schema_version = 50;

    var $is_cli_mode_required = false;

    const COLUMNS = array( 'transaction_id', 'tax', 'shipping' );

    function up( $force = false ) {

        foreach ( self::COLUMNS as $column ) {

            if ( $this->addRawColumn( $column ) === false ) {

                return false;
            }
        }

        return true;
    }

    function down() {

        $raw = \OWA\Core\CoreAPI::entityFactory( 'base.event_raw' );

        foreach ( self::COLUMNS as $column ) {

            if ( $this->dropCubeColumn( $column ) === false ) {

                return false;
            }

            if ( $this->dropColumnIfPresent( $raw, $column ) === false ) {

                $this->e->notice( sprintf(
                    'Dropping %s.%s failed', $raw->getTableName(), $column ) );

                return false;
            }
        }

        return true;
    }
}

?>
