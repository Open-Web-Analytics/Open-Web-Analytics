import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';
import { OWA_instance as OWA } from '../../modules/Base/src/common/owa.js';

/**
 * trackPurchase(), and the legacy transaction API storing items in the same shape.
 */

function newTracker() {
    const t = new OWATracker({ cookie_domain_set: true, cookie_domain: '.shop.example' });
    t.setSiteId('purchase-site');
    const sent = [];
    t.logEvent = (properties) => { sent.push(properties); return true; };
    return { t, sent };
}

beforeEach(() => {
    OWA.setSetting('ns', 'owa_');
    OWA.setSetting('loggerPause', false);
});

describe('trackPurchase', () => {

    test('sends the purchase, with value, tax and shipping as separate amounts', () => {
        const { t, sent } = newTracker();

        expect(t.trackPurchase({
            transaction_id: 'T-1001', value: '59.98', currency: 'usd', tax: 4.9, shipping: 5.99,
            coupon: 'SPRING', affiliation: 'Web store',
            items: [
                { item_id: 'SKU-1', item_name: 'Blue mug', item_category: 'Kitchen', item_brand: 'Acme',
                  item_variant: 'Large', price: '19.99', quantity: 2, coupon: 'MUG10', discount: 2, colour: 'blue' },
                { item_name: 'Tea', price: 20, quantity: 1 },
            ],
        })).toBe(true);

        const p = sent.find(e => e.event_type === 'purchase');

        expect(p.ct_order_id).toBe('T-1001');
        expect(p.ct_value).toBe(59.98);
        expect(p.ct_tax).toBe(4.9);
        expect(p.ct_shipping).toBe(5.99);
        expect(p.ct_total).toBeUndefined();
        expect(p.currency).toBe('USD');
        expect(p.coupon).toBe('SPRING');
        expect(p.ct_order_source).toBe('Web store');

        expect(p.ct_line_items).toEqual([
            { item_id: 'SKU-1', item_name: 'Blue mug', item_category: 'Kitchen', item_brand: 'Acme',
              item_variant: 'Large', coupon: 'MUG10', price: 19.99, quantity: 2, discount: 2 },
            { item_name: 'Tea', price: 20, quantity: 1 },
        ]);
    });

    test('a purchase with no transaction_id is refused', () => {
        const { t, sent } = newTracker();

        expect(t.trackPurchase({ value: 10 })).toBe(false);
        expect(t.trackPurchase({ transaction_id: '  ', value: 10 })).toBe(false);
        expect(sent.filter(e => e.event_type === 'purchase')).toHaveLength(0);
    });

    test('an item naming neither an id nor a name is dropped', () => {
        const { t, sent } = newTracker();

        t.trackPurchase({ transaction_id: 'T-2', items: [{ price: 5 }, { item_id: 'A' }] });

        expect(sent.find(e => e.event_type === 'purchase').ct_line_items).toEqual([{ item_id: 'A' }]);
    });
});

describe('the legacy transaction API', () => {

    test('a line item is stored in the same shape trackPurchase takes', () => {
        const { t, sent } = newTracker();

        t.addTransaction('o1', 'web', 25, 0, 0, 'gw');
        expect(t.addTransactionLineItem('o1', 'SKU-1', 'Blue mug', 'Kitchen', 12.5, 2)).toBe(true);
        t.trackTransaction();

        expect(sent.find(e => e.event_type === 'purchase').ct_line_items).toEqual([
            { item_id: 'SKU-1', item_name: 'Blue mug', item_category: 'Kitchen', price: 12.5, quantity: 2 },
        ]);
    });

    /*
     * It opened a transaction called 'none set', so an item added out of order
     * became a purchase with no order id, total or currency.
     */
    test('a line item with no open transaction is refused', () => {
        const { t, sent } = newTracker();

        expect(t.addTransactionLineItem('o1', 'SKU-1', 'Blue mug', 'Kitchen', 12.5, 2)).toBe(false);
        t.trackTransaction();

        expect(sent.filter(e => e.event_type === 'purchase')).toHaveLength(0);
    });
});

describe('trackRefund', () => {

    test('a refund of the whole purchase names only the transaction', () => {
        const { t, sent } = newTracker();

        expect(t.trackRefund({ transaction_id: 'T-1001' })).toBe(true);

        const r = sent.find(e => e.event_type === 'refund');
        expect(r.ct_order_id).toBe('T-1001');
        expect(r.ct_value).toBeUndefined();
        expect(r.ct_line_items).toBeUndefined();
    });

    test('a partial refund carries its value, or its items', () => {
        const { t, sent } = newTracker();

        t.trackRefund({ transaction_id: 'T-1', value: '19.99', currency: 'eur' });
        t.trackRefund({ transaction_id: 'T-2', items: [{ item_id: 'SKU-1', price: 19.99, quantity: 1 }] });

        const [byValue, byItems] = sent.filter(e => e.event_type === 'refund');

        expect(byValue.ct_value).toBe(19.99);
        expect(byValue.currency).toBe('EUR');
        expect(byItems.ct_line_items).toEqual([{ item_id: 'SKU-1', price: 19.99, quantity: 1 }]);
    });

    test('a refund with no transaction_id is refused', () => {
        const { t, sent } = newTracker();

        expect(t.trackRefund({ value: 5 })).toBe(false);
        expect(sent.filter(e => e.event_type === 'refund')).toHaveLength(0);
    });
});

