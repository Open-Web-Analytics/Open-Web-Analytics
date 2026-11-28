/**
 * What a page hands the deferred reporting bundle as JSON: the settings
 * (config_dom.php, OWA.readPageConfig()) and the report's object
 * (report.php, OWA.report.item()).
 */
// The report imports jQuery as a namespace and calls it; see DataGrid.test.js.
jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

import { OWA } from '../../modules/Base/src/reporting/owa.format.js';

global.jQuery = global.$ = require('jquery');

require('../../modules/Base/src/reporting/owa.report.js');

afterEach(() => {
    document.body.innerHTML = '';
    delete OWA.items['owa_reportPage'];
    delete OWA.config.zz_probe;
});

describe('the page settings', () => {

    test('are read into OWA.config', () => {
        document.body.innerHTML = '<script type="application/json" data-ui="owa:config">'
            + '{"zz_probe":"set","locale":"de"}</script>';

        OWA.readPageConfig(document);

        expect(OWA.config.zz_probe).toBe('set');
        expect(OWA.config.locale).toBe('de');
    });

    test('a page without them keeps the defaults, and an unreadable block changes nothing', () => {
        const baseUrl = OWA.config.baseUrl;

        OWA.readPageConfig(document);
        document.body.innerHTML = '<script type="application/json" data-ui="owa:config">{not json</script>';
        OWA.readPageConfig(document);

        expect(OWA.config.baseUrl).toBe(baseUrl);
        expect(OWA.config.zz_probe).toBeUndefined();
    });
});

describe('the report object', () => {

    test('is made from the page the first time it is asked for, then kept', () => {
        document.body.innerHTML = '<script type="application/json" data-ui="report:item">'
            + '{"dom_id":"owa_reportPage","properties":{"period":"today"}}</script>';

        const report = OWA.report.item('owa_reportPage');

        expect(report).toBeInstanceOf(OWA.report);
        expect(report.dom_id).toBe('owa_reportPage');
        expect(report.properties).toEqual({ period: 'today' });
        expect(OWA.report.item('owa_reportPage')).toBe(report);
        expect(OWA.items['owa_reportPage']).toBe(report);
    });

    test('is undefined for a report the page does not have', () => {
        expect(OWA.report.item('owa_reportNone')).toBeUndefined();
    });
});
