<?php
namespace OWA\Module\Base\Classes\Migration;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * v1's orders (owa_commerce_transaction_fact) into purchase events.
 *
 * v1 stored money as the amount times 100 whatever the currency
 * (Lib::prepareCurrencyValue); v2 stores minor units by the currency's own
 * exponent. So an amount is divided by 100 and converted with the Profile's
 * currency -- a yen total is not multiplied by 100 twice.
 *
 * Line items are not migrated: v2's row carries one total per purchase and has
 * no item-level shape yet.
 */
class PurchaseMigrator extends FactMigrator {

    const SOURCE = 'commerce_transaction_fact';

    protected function events( array $r, array $refs ) {

        $event = $this->baseEvent( $r, $refs, 'purchase', array(
            'ct_order_id'    => $r['order_id'] ?? null,
            'ep_order_source' => $r['order_source'] ?? null,
            'ep_gateway'      => $r['gateway'] ?? null,
        ) );

        $currency = \OWA\Module\Base\Classes\TrackingEventHelpers::purchaseCurrency( $event );

        $event->set( 'currency', $currency );

        foreach ( array( 'revenue' => 'total_revenue', 'ct_tax' => 'tax_revenue', 'ct_shipping' => 'shipping_revenue' ) as $to => $from ) {

            $v1 = $r[ $from ] ?? null;

            $event->set( $to, is_numeric( $v1 )
                ? \OWA\Module\Base\Classes\Currency::toMinorUnits( $v1 / 100, $currency )
                : null );
        }

        return array( $event );
    }
}

?>
