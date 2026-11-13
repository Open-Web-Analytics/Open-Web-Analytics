// The reporting bundle calls jQuery(...) at load. Same interop fix as the other
// reporting specs: babel-jest's wildcard namespace is not callable, so mark the
// real jQuery as an ES module.
jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

import { OWA } from '../../../modules/Base/src/reporting/v1/owa.js';

const fs = require('fs');
const path = require('path');

/**
 * The reporting bundle prefixes cookie names and nothing of its own.
 *
 *   config.ns  'owa_'  -- the WIRE namespace. Cookie names, in a jar shared
 *                         with everything else on the domain.
 *   OWA's OWN admin/reporting URLs carry bare names: OWA owns the entire query
 *   string.
 *
 * They were a single setting, and that is the trap: OWA.stateManager names its
 * cookies with OWA.getSetting('ns'), while the results explorer builds report
 * URLs from what used to be the same value. Emptying the one setting to get
 * un-prefixed links would silently rename every reporting cookie, orphaning the
 * state OWA had already stored under the old names -- so the two surfaces are
 * pinned separately here.
 */
describe('the reporting bundle prefixes cookies and not its own URLs', () => {

    afterEach(() => {
        OWA.setOption('ns', 'owa_');
    });

    test('the wire namespace is still owa_, and there is no app namespace', () => {
        expect(OWA.config.ns).toBe('owa_');
        expect(OWA.util.ns('v')).toBe('owa_v');
        expect('app_ns' in OWA.config).toBe(false);
        expect(OWA.util.appNs).toBeUndefined();
    });

    /**
     * The surface that must NOT have moved. A cookie name is shared with the
     * tracker and with whatever else lives on the domain.
     */
    test('state cookies are still written under the wire namespace', () => {
        const sm = new OWA.stateManager();
        sm.registerStore('rs', 30, '', 'json');
        sm.set('rs', 'period', 'last_thirty_days', true, 'json', 30);

        expect(document.cookie).toContain('owa_rs=');
        // and not bare
        expect(document.cookie).not.toMatch(/(^|;\s*)rs=/);
    });

    /**
     * ...and is read back under the same name, so a rename would not merely
     * orphan the old cookie, it would orphan it in both directions.
     */
    test('state cookies are read back under the wire namespace', () => {
        document.cookie = 'owa_rt=' + escape(JSON.stringify({ period: 'today' })) + '; path=/';

        const sm = new OWA.stateManager();
        sm.registerStore('rt', 30, '', 'json');

        expect(sm.getStateFromCookie('rt')).toContain('today');
    });

    /**
     * Report URLs are built by the SERVER (PaginatedResultSet emits the result
     * set's own self/next/previous links) and taken apart again here, so both
     * ends have to agree. The explorer used to hardcode 'owa_sort',
     * 'owa_constraints', 'owa_dimensions', 'owa_page' and 'owa_do=' -- which
     * agreed with the server only by coincidence, and stopped agreeing the
     * moment the server's links dropped the prefix.
     */
    describe('the results explorer builds URLs with bare names', () => {

        const src = fs.readFileSync(
            path.join(__dirname, '../../../modules/Base/src/reporting/v1/owa.resultSetExplorer.js'),
            'utf8'
        );

        // Only unambiguous param contexts -- CSS class names in this file are
        // owa_-prefixed too and are none of this test's business.
        const HARDCODED = /(?:get|set|remove)QueryParam\(\s*'owa_|'owa_do=|'&owa_/g;

        test('the scan can actually fail', () => {
            // Guard against the regex quietly matching nothing forever.
            expect("url.setQueryParam('owa_sort', x)").toMatch(HARDCODED);
            expect(src.length).toBeGreaterThan(1000);
        });

        test('no param name is hardcoded with the wire prefix', () => {
            expect(src.match(HARDCODED)).toBeNull();
        });

        test('the param names are bare', () => {
            expect(src).toContain("url.setQueryParam('sort', ");
            expect(src).toContain("url.setQueryParam('constraints', ");
            expect(src).toContain("url.getQueryParam('dimensions')");
            expect(src).toContain("url += 'do=' + method");
            expect(src).not.toContain('appNs');
        });
    });
});
