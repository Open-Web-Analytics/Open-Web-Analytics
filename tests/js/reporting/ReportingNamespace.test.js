// The reporting bundle calls jQuery(...) at load. Same interop fix as the other
// reporting specs: babel-jest's wildcard namespace is not callable, so mark the
// real jQuery as an ES module.
jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

import { OWA } from '../../../modules/Base/src/reporting/owa.format.js';

const fs = require('fs');
const path = require('path');

/**
 * The reporting bundle's own URLs carry bare parameter names.
 *
 *   OWA's OWN admin/reporting URLs carry bare names: OWA owns the entire query
 *   string. nsAll() prefixes with owa_, a constant, not a setting.
 */
describe('the reporting bundle keeps the wire namespace out of its own URLs', () => {

    afterEach(() => {
        delete OWA.config.ns;
    });

    test('the prefix is owa_ whatever the config says, and there is no app namespace', () => {
        expect('ns' in OWA.config).toBe(false);
        OWA.setOption('ns', 'xy_');
        expect(OWA.util.nsAll({ do: 'base.reportDashboard' })).toEqual({ owa_do: 'base.reportDashboard' });
        expect('app_ns' in OWA.config).toBe(false);
        expect(OWA.util.appNs).toBeUndefined();
    });

    /**
     * Report URLs are built by the SERVER (PaginatedResultSet emits the result
     * set's own self/next/previous links) and taken apart again here, so both
     * ends have to agree on bare names.
     */
    describe('the results explorer builds URLs with bare names', () => {

        const src = fs.readFileSync(
            path.join(__dirname, '../../../modules/Base/src/reporting/owa.resultSetExplorer.js'),
            'utf8'
        );

        // Only unambiguous param contexts -- CSS class names in this file are
        // owa_-prefixed too and are none of this test's business.
        const HARDCODED = /searchParams\.(?:get|set|delete|has)\(\s*'owa_|'owa_do=|'&owa_/g;

        test('the scan can actually fail', () => {
            // Guard against the regex quietly matching nothing forever.
            expect("url.searchParams.set('owa_sort', x)").toMatch(HARDCODED);
            expect(src.length).toBeGreaterThan(1000);
        });

        test('no param name is hardcoded with the wire prefix', () => {
            expect(src.match(HARDCODED)).toBeNull();
        });

        test('the param names are bare', () => {
            expect(src).toContain("url.searchParams.set('sort', ");
            expect(src).toContain("url.searchParams.set('constraints', ");
            expect(src).toContain("url.searchParams.get('dimensions')");
            expect(src).not.toContain('appNs');
        });
    });
});
