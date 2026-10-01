import fs from 'fs';
import path from 'path';
import { WIRE_NAMES, toWire } from '../../modules/Base/src/tracker/WireNames.js';
import { OWATracker } from '../../modules/Base/src/tracker/Tracker.js';

/**
 * The tracker's translation to short wire keys (WireNames.js).
 *
 * The server side of the same table is held to this one by
 * BeaconWireNamesTest.php; this covers the translation and that a real beacon
 * carries no long name.
 */

const CONTRACTS = JSON.parse(
    fs.readFileSync(path.join(__dirname, '../fixtures/beacon_contracts.json'), 'utf8')
);

const CUSTOM = /^(eps|epn|vps|vpn)_/;

describe('toWire', () => {

    test('renames each property to its short key and keeps the value', () => {
        expect(toWire({ event_type: 'click', page_location: 'https://a.example/', click_x: 4 }))
            .toEqual({ e_t: 'click', p_l: 'https://a.example/', c_x: 4 });
    });

    test('flags travel as 1 and 0, from booleans or their strings', () => {
        expect(toWire({ is_new_session_start: true, is_new_visitor_created: 'false', is_outbound: 'true' }))
            .toEqual({ s_new: 1, v_new: 0, el_lo: 1 });
        expect(toWire({ is_outbound: false })).toEqual({ el_lo: 0 });
    });

    test('a name the table does not hold travels as it is', () => {
        expect(toWire({ eps_plan: 'pro', vpn_seats: 3, samples: '[]' }))
            .toEqual({ eps_plan: 'pro', vpn_seats: 3, samples: '[]' });
    });

    test('no two properties share a key', () => {
        const keys = Object.values(WIRE_NAMES);
        expect(new Set(keys).size).toBe(keys.length);
    });

    test('every property in the current contract has a short key or a custom prefix', () => {
        const missing = [];

        for (const [shape, names] of Object.entries(CONTRACTS['2'])) {
            for (const name of names) {
                if (!(name in WIRE_NAMES) && !CUSTOM.test(name)) {
                    missing.push(shape + ': ' + name);
                }
            }
        }

        expect(missing).toEqual([]);
    });
});

describe('a real beacon', () => {

    test('carries short keys and no long name', () => {
        window.owa_baseUrl = 'https://owa.example.test/';
        const sent = [];
        const Orig = global.Image;
        global.Image = class { set src(v) { sent.push(v); } };

        try {
            const t = new OWATracker({ cookie_domain_set: true });
            t.setSiteId('wire-site');
            t.trackPageView('https://site.example/p');

            expect(sent).toHaveLength(1);
            const keys = [...new URL(sent[0]).searchParams.keys()];

            expect(keys).toEqual(expect.arrayContaining(['e_t', 'site', 'p_l', 'v_id', 's_id', '_v']));
            expect(keys.filter((k) => k in WIRE_NAMES)).toEqual([]);
        } finally {
            global.Image = Orig;
        }
    });
});
