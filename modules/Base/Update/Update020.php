<?php

namespace OWA\Module\Base\Update;

/**
 * Gives a commerce transaction its own billing-address columns.
 *
 * The billing address used to arrive as country/city/state -- the names of the
 * server-derived geolocation properties -- and was used to build the
 * transaction's location_id. So a transaction's location meant the buyer's
 * billing address while every other event type meant where the visitor's IP
 * said they were, and once written the two were indistinguishable in
 * owa_location.
 *
 * They are different facts. The visitor's location stays in the geolocation
 * dimension where location_id points, and the billing address lives on the
 * transaction row.
 *
 * Existing rows are not backfilled: the billing address they were built from
 * was never stored separately, so there is nothing to recover it from. Their
 * location_id keeps pointing at whatever it did.
 */
class Update020 extends \OWA\Core\Update {

    var $schema_version = 20;
    var $is_cli_mode_required = false;

    function up( $force = false ) {

        $entity = \OWA\Core\CoreAPI::entityFactory( 'base.commerce_transaction_fact' );

        foreach ( array( 'billing_country', 'billing_state', 'billing_city' ) as $column ) {

            // Skipped when already present -- see Update::addColumnIfMissing().
            // Found latent here by the guard test rather than by an upgrade
            // failing: this one has already run everywhere it was going to.
            if ( ! $this->addColumnIfMissing( $entity, $column ) ) {

                $this->e->notice( "Adding $column to commerce_transaction_fact failed" );

                return false;
            }
        }

        return true;
    }

    function down() {

        return false;
    }
}

?>
