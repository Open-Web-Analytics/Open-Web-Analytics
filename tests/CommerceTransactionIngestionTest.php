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
 * The first test deliberately omits original_session_id so the handler does not
 * try to load a prior base.session (which would fail with no session context).
 *
 * The remaining tests cover the DELAYED / async transaction path
 * (commerceTransactionHandlers::notify, the original_session_id branch). This is
 * how the PHP SDK / log.php record a purchase that could not be authorised
 * inside the buyer's original web request (e.g. PayPal IPN). It is a distinct,
 * more heavily branched code path than the in-session case above and is what the
 * "Tracking Delayed E-commerce Transactions" wiki section documents:
 *
 *   - original_session_id rebinds the transaction to the buyer's earlier
 *     session and merges that session's dimensions (visitor, etc.) onto the
 *     transaction, so a delayed purchase is attributed to the visit that earned
 *     it rather than to the out-of-band callback.
 *   - a bad/unknown original_session_id makes the handler abort (return
 *     OWA_EHS_EVENT_FAILED) BEFORE writing a transaction row, so we never
 *     persist an unattributable, dimension-less transaction.
 *
 * Note: original_session_id is intentionally NOT in beacon_contracts.json — it
 * is an SDK/log.php-only param, never emitted by the JS tracker — so these
 * tests do not (and must not) call assertFieldsInContract() for it.
 */
final class CommerceTransactionIngestionTest extends IngestionTestCase
{
    public function testTransactionPersistsTransactionAndLineItemRows(): void
    {
        // The transaction beacon is the one place the buyer's location arrives
        // on the wire (country/city/state), so it is the honest home for a
        // location_dim assertion. Guard that those really are contracted fields.
        $this->assertFieldsInContract('ecommerce.transaction', ['ct_country', 'ct_city', 'ct_state']);

        $site_id  = md5('owa-test-site');
        // Unique order id so the PK (generateId(order_id)) is unique per run.
        $order_id = 'owatest-order-' . uniqid('', true);
        $sku      = 'SKU-1';

        // Unique country so the derived location_dim row is authored by THIS
        // transaction. resolveCountry() honours a manually-supplied country
        // before ever consulting the geo module, and generateLocationId() keys
        // the location_dim row on country — so this works with geo inactive and
        // gives us a unique row to assert and clean up (rather than the shared
        // '(not set)' default a pageview lands on here).
        $country = 'OWAtestland ' . $order_id;

        // Compute the PKs the handler will use, for load-back and cleanup.
        $txn_entity = owa_coreAPI::entityFactory('base.commerce_transaction_fact');
        $txn_pk     = $txn_entity->generateId($order_id);
        $li_entity  = owa_coreAPI::entityFactory('base.commerce_line_item_fact');
        $li_pk      = $li_entity->generateId($order_id . $sku);
        $this->trackForCleanup('base.commerce_transaction_fact', $txn_pk, 'id');
        $this->trackForCleanup('base.commerce_line_item_fact', $li_pk, 'id');
        $this->trackForCleanup('base.location_dim', $country, 'country');

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
            // Billing address, under the ct_ prefix its sibling transaction
            // fields use. Sending it as country/city/state made it overwrite the
            // geolocation derived from the visitor's IP -- two different facts
            // sharing three names.
            'ct_country'      => $country,
            'ct_city'         => 'Testville',
            'ct_state'        => 'TS',
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

        // location dimension: the transaction fan-out authored a real, unique
        // location_dim row keyed on the buyer's country (not the shared
        // '(not set)' default), proving the geo/location dimension gets logged.
        /*
         * The billing address is on the TRANSACTION now, not in the geolocation
         * dimension. It used to be asserted against a location_dim row because
         * it was sent under the geolocation property names and built this
         * transaction's location_id -- so a buyer's billing address and a
         * visitor's IP-derived location were the same column for one event type
         * and different things for every other.
         */
        $this->assertSame($country, $txn->get('billing_country'));
        $this->assertSame('Testville', $txn->get('billing_city'));
        $this->assertSame('TS', $txn->get('billing_state'));
    }

    /**
     * Delayed transaction, happy path: a transaction sent later with
     * original_session_id set to a real prior session is attributed to that
     * session. The handler rebinds session_id to the original session and merges
     * the original session's properties (setNewProperties) onto the transaction,
     * so the transaction fact carries the buyer's earlier session/visitor rather
     * than the out-of-band context in which the callback arrived.
     */
    public function testDelayedTransactionAttributesToOriginalSession(): void
    {
        $site_id    = md5('owa-test-site');
        $session_id = $this->uniqueSessionId();
        $visitor_id = $this->uniqueGuid();
        $this->trackForCleanup('base.session', $session_id, 'id');

        // 1) Seed a REAL original session by firing the page_request that opens
        //    it (this is the session the delayed transaction will reference).
        //    The tracker emits session_id + visitor_id on a new-session pageview;
        //    logSession() sweeps them onto the base.session row.
        $req_guid = $this->uniqueGuid();
        $this->trackForCleanup('base.request', $req_guid, 'id');
        $this->setServerTime(1700000000);
        $seed = $this->fireEvent('base.page_request', [
            'guid'           => $req_guid,
            'site_id'        => $site_id,
            'session_id'     => $session_id,
            'visitor_id'     => $visitor_id,
            'is_new_session' => true,
            'page_url'       => 'https://example.com/shop/product',
        ]);
        $this->assertNotFalse($seed, 'seed page_request was dropped before persistence.');
        $session = $this->assertRowPersisted('base.session', $session_id, 'id');
        // The merge is only meaningful if the seeded session really carries the
        // dimension we expect to see copied onto the transaction.
        $this->assertSame($visitor_id, (string) $session->get('visitor_id'), 'seeded session did not capture visitor_id.');

        // 2) Fire the DELAYED transaction: no session_id on the wire, only
        //    original_session_id — exactly what the PHP SDK / log.php send for a
        //    transaction authorised after the buyer's request ended.
        $order_id   = 'owatest-delayed-' . uniqid('', true);
        $txn_entity = owa_coreAPI::entityFactory('base.commerce_transaction_fact');
        $txn_pk     = $txn_entity->generateId($order_id);
        $this->trackForCleanup('base.commerce_transaction_fact', $txn_pk, 'id');

        $result = $this->fireEvent('ecommerce.transaction', [
            'guid'                => $this->uniqueGuid(),
            'site_id'             => $site_id,
            'original_session_id' => $session_id,
            'page_url'            => 'https://example.com/checkout/thankyou',
            'ct_order_id'         => $order_id,
            'ct_order_source'     => 'Web',
            'ct_gateway'          => 'PayPal',
            'ct_total'            => 30.00,
            'ct_tax'              => 0.00,
            'ct_shipping'         => 0.00,
        ]);
        $this->assertNotFalse($result, 'delayed transaction was dropped before persistence.');

        $txn = $this->assertRowPersisted('base.commerce_transaction_fact', $txn_pk, 'id');
        $this->assertSame($order_id, $txn->get('order_id'));
        // The handler rebinds session_id to the original session ...
        $this->assertSame($session_id, (string) $txn->get('session_id'), 'delayed transaction was not bound to the original session_id.');
        // ... and merges the original session's dimensions onto the transaction,
        // so the earlier visit's visitor_id is attributed to this purchase.
        $this->assertSame($visitor_id, (string) $txn->get('visitor_id'), 'original session dimensions were not merged onto the delayed transaction.');
    }

    /**
     * Delayed transaction, failure path: an original_session_id that matches no
     * stored session makes the handler abort (return OWA_EHS_EVENT_FAILED)
     * BEFORE creating any row. This guards against persisting an unattributable,
     * dimension-less transaction (and lets the processing queue retry it once
     * the referenced session has been written by an out-of-order logger).
     *
     * We assert on the observable outcome — no transaction row — rather than on
     * logEvent()'s return value: the beacon dispatch path (processEvent::
     * addToEventQueue) calls the dispatcher but discards its response and
     * returns null, so the handler's OWA_EHS_EVENT_FAILED does not propagate
     * back to the caller. The row-absence check is the real, stable contract.
     */
    public function testDelayedTransactionWithUnknownSessionPersistsNothing(): void
    {
        $site_id  = md5('owa-test-site');
        // A well-formed but nonexistent session id (never seeded).
        $missing  = $this->uniqueSessionId();
        $order_id = 'owatest-orphan-' . uniqid('', true);

        $txn_entity = owa_coreAPI::entityFactory('base.commerce_transaction_fact');
        $txn_pk     = $txn_entity->generateId($order_id);
        // Register cleanup defensively in case the guard ever regresses and a row
        // slips through — tearDown then removes it rather than leaving residue.
        $this->trackForCleanup('base.commerce_transaction_fact', $txn_pk, 'id');

        $this->fireEvent('ecommerce.transaction', [
            'guid'                => $this->uniqueGuid(),
            'site_id'             => $site_id,
            'original_session_id' => $missing,
            'page_url'            => 'https://example.com/checkout/thankyou',
            'ct_order_id'         => $order_id,
            'ct_order_source'     => 'Web',
            'ct_gateway'          => 'PayPal',
            'ct_total'            => 15.00,
            'ct_tax'              => 0.00,
            'ct_shipping'         => 0.00,
        ]);

        // Critically: the handler bailed on the missing session, so no
        // transaction row was written.
        // wasPersisted() is only set true on a successful load; an absent row
        // leaves it at its uninitialised null, so assert "not persisted" with
        // assertEmpty rather than a strict false.
        $orphan = owa_coreAPI::entityFactory('base.commerce_transaction_fact');
        $orphan->load($txn_pk, 'id');
        $this->assertEmpty(
            $orphan->wasPersisted(),
            'a transaction with an unknown original_session_id must NOT be persisted.'
        );
    }
}
