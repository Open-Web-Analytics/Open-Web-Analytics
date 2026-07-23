<?php

require_once __DIR__ . '/IngestionTestCase.php';

/**
 * Beacon-contract test for the ecommerce.transaction pipeline.
 *
 * event_type ecommerce.transaction -> owa_commerceTransactionHandlers ->
 * base.commerce_transaction_fact (table owa_commerce_transaction_fact) plus one
 * base.commerce_line_item_fact (table owa_commerce_line_item_fact) per line item.
 *
 * Unlike the other handlers, the row PKs are NOT the event guid:
 *   - transaction PK = generateId(ct_order_id)
 *   - line item PK   = generateId(li_order_id . li_sku)
 * so the test computes those to load the rows back and to clean them up.
 *
 * Currency fields are stored as integer "cents" (prepareCurrencyValue = *100).
 * We deliberately omit original_session_id so the handler does not try to load a
 * prior base.session (which would fail with no session context).
 */
final class CommerceTransactionIngestionTest extends IngestionTestCase
{
    public function testTransactionPersistsTransactionAndLineItemRows(): void
    {
        $site_id  = md5('owa-test-site');
        // Unique order id so the PK (generateId(order_id)) is unique per run.
        $order_id = 'owatest-order-' . uniqid('', true);
        $sku      = 'SKU-1';

        // Compute the PKs the handler will use, for load-back and cleanup.
        $txn_entity = owa_coreAPI::entityFactory('base.commerce_transaction_fact');
        $txn_pk     = $txn_entity->generateId($order_id);
        $li_entity  = owa_coreAPI::entityFactory('base.commerce_line_item_fact');
        $li_pk      = $li_entity->generateId($order_id . $sku);
        $this->trackForCleanup('base.commerce_transaction_fact', $txn_pk, 'id');
        $this->trackForCleanup('base.commerce_line_item_fact', $li_pk, 'id');

        $result = $this->fireEvent('ecommerce.transaction', [
            'guid'            => $this->uniqueGuid(),
            'site_id'         => $site_id,
            'page_url'        => 'https://example.com/checkout/thankyou',
            'ct_order_id'     => $order_id,
            'ct_order_source' => 'Web',
            'ct_gateway'      => 'Stripe',
            'ct_total'        => 42.50,
            'ct_tax'          => 2.50,
            'ct_shipping'     => 5.00,
            'ct_line_items'   => [[
                'li_order_id'     => $order_id,
                'li_sku'          => $sku,
                'li_product_name' => 'Test Widget',
                'li_category'     => 'widgets',
                'li_unit_price'   => 20.00,
                'li_quantity'     => 2,
            ]],
        ]);
        $this->assertNotFalse(
            $result,
            'logEvent returned false — the transaction was dropped before persistence.'
        );

        // Transaction row: loaded by the order-id-derived PK, not the guid.
        $txn = $this->assertRowPersisted('base.commerce_transaction_fact', $txn_pk, 'id');
        $this->assertSame($site_id, $txn->get('site_id'));
        $this->assertSame($order_id, $txn->get('order_id'));
        // order_source and gateway are trimmed + lowercased.
        $this->assertSame('web', $txn->get('order_source'));
        $this->assertSame('stripe', $txn->get('gateway'));
        // Currency stored as integer cents (value * 100).
        $this->assertEquals(4250, $txn->get('total_revenue'));
        $this->assertEquals(250, $txn->get('tax_revenue'));
        $this->assertEquals(500, $txn->get('shipping_revenue'));

        // Line item row: loaded by generateId(order_id . sku).
        $li = $this->assertRowPersisted('base.commerce_line_item_fact', $li_pk, 'id');
        $this->assertSame($order_id, $li->get('order_id'));
        $this->assertSame($sku, $li->get('sku'));
        // product_name is trimmed + lowercased.
        $this->assertSame('test widget', $li->get('product_name'));
        $this->assertEquals(2, $li->get('quantity'));
        $this->assertEquals(2000, $li->get('unit_price'));   // 20.00 * 100
        $this->assertEquals(4000, $li->get('item_revenue')); // 2 * 20.00 * 100
    }
}
