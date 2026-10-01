<?php
namespace OWA\Module\Base\Classes\Migration;

//
// Open Web Analytics - An Open Source Web Analytics Framework
//
// Licensed under GPL v2.0 http://www.gnu.org/copyleft/gpl.html
//

/**
 * v1's orders (owa_commerce_transaction_fact) into purchase events, with their
 * line items (owa_commerce_line_item_fact).
 *
 * v1 stored money as the amount times 100 whatever the currency
 * (Lib::prepareCurrencyValue); v2 stores minor units by the currency's own
 * exponent. So an amount is divided by 100 and converted with the Profile's
 * currency -- a yen total is not multiplied by 100 twice.
 *
 * LINE ITEMS GO WHERE LIVE INGEST PUTS THEM: params.items, in the shape the
 * tracker sends (OWATracker.purchaseItem) -- item_id, item_name,
 * item_category, price in MAJOR units, quantity. So a report reading items
 * reads a migrated purchase and a live one the same way. They are migrated
 * because nothing else keeps them: once v1-drop runs, v1's line-item table is
 * the only record of what an installation sold, and it would be gone.
 *
 * item_revenue is not carried. v2's item is price times quantity, which is
 * what v1 wrote it as.
 */
class PurchaseMigrator extends FactMigrator {

    const SOURCE = 'commerce_transaction_fact';

    const LINE_ITEMS = 'commerce_line_item_fact';

    protected function events( array $r, array $refs ) {

        $event = $this->baseEvent( $r, $refs, 'purchase', array(
            'ct_order_id'      => $r['order_id'] ?? null,
            'eps_order_source' => $r['order_source'] ?? null,
            'eps_gateway'      => $r['gateway'] ?? null,
        ) );

        $currency = \OWA\Module\Base\Classes\TrackingEventHelpers::purchaseCurrency( $event );

        $event->set( 'currency', $currency );

        foreach ( array( 'revenue' => 'total_revenue', 'ct_tax' => 'tax_revenue', 'ct_shipping' => 'shipping_revenue' ) as $to => $from ) {

            $v1 = $r[ $from ] ?? null;

            $event->set( $to, is_numeric( $v1 )
                ? \OWA\Module\Base\Classes\Currency::toMinorUnits( $v1 / 100, $currency )
                : null );
        }

        $order = (string) ( $r['order_id'] ?? '' );
        $items = $order !== '' ? ( $refs['line_items'][ $order ] ?? array() ) : array();

        if ( $items ) {

            $event->set( 'ct_line_items', $items );
        }

        return array( $event );
    }

    /**
     * The batch's line items, keyed by order id, with the dimension lookups.
     *
     * One query per batch, by site and order: an order id is only unique
     * within a site. In v1 id order, which is the order they were recorded
     * in -- the order a report lists them in.
     */
    protected function resolve( array $rows ) {

        $refs = parent::resolve( $rows );

        $refs['line_items'] = array();

        $orders = array_values( array_unique( array_filter(
            array_map( 'strval', array_column( $rows, 'order_id' ) ), 'strlen' ) ) );

        $table = $this->v1Table( self::LINE_ITEMS );

        if ( ! $orders || ! $this->db()->tableExists( $table ) ) {

            return $refs;
        }

        $site = (string) $rows[0]['site_id'];

        $found = (array) $this->db()->get_results( sprintf(
            'SELECT order_id, sku, product_name, category, unit_price, quantity FROM %s'
          . ' WHERE site_id = ? AND order_id IN (%s) ORDER BY id',
            $table, implode( ',', array_fill( 0, count( $orders ), '?' ) ) ),
            array_merge( array( $site ), $orders ) );

        foreach ( $found as $li ) {

            $li   = (array) $li;
            $item = self::item( $li );

            if ( $item ) {

                $refs['line_items'][ (string) $li['order_id'] ][] = $item;
            }
        }

        return $refs;
    }

    /**
     * One v1 line item in the tracker's shape: known fields only, numbers as
     * numbers, and null for an item naming neither a SKU nor a product --
     * the same rule OWATracker.purchaseItem applies at collection.
     *
     * @param  array $li a commerce_line_item_fact row
     * @return array|null
     */
    public static function item( array $li ) {

        $out = array();

        foreach ( array( 'item_id' => 'sku', 'item_name' => 'product_name', 'item_category' => 'category' ) as $to => $from ) {

            $v = trim( (string) ( $li[ $from ] ?? '' ) );

            if ( $v !== '' ) {

                $out[ $to ] = $v;
            }
        }

        if ( ! isset( $out['item_id'] ) && ! isset( $out['item_name'] ) ) {

            return null;
        }

        if ( is_numeric( $li['unit_price'] ?? null ) ) {

            // v1's times-100, whatever the currency: 1999 was 19.99. A whole
            // amount is an integer, as the tracker's JS number decodes at ingest.
            $price        = round( $li['unit_price'] / 100, 2 );
            $out['price'] = floor( $price ) == $price ? (int) $price : $price;
        }

        if ( is_numeric( $li['quantity'] ?? null ) ) {

            $out['quantity'] = (int) $li['quantity'];
        }

        return $out;
    }
}

?>
