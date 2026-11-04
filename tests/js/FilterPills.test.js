// The pills import jQuery as a namespace and call it. Webpack makes that
// callable; babel-jest's interop does only when jQuery is marked an ES module.
jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

import { OWA } from '../../modules/Base/src/reporting/v1/owa.js';

const $ = require('jquery');

global.jQuery = global.$ = $;

require('../../modules/Base/src/reporting/v1/owa.resultSetExplorer.js');
require('../../modules/Base/src/reporting/v1/owa.filterpills.js');

/**
 * The filter pills (OWA.filterPills) of the report editor and the funnel
 * builder: how a stored constraints string is read back into pills.
 *
 * A clause is split where the server splits it, as the report grid's chips are
 * (OWA.dataGrid.parseConstraints()). The pills read with only the five
 * operators their form offers, so a clause using any other came back split at
 * the wrong place, or not at all and was then stored as `<clause>==`.
 */

/*
 * What config_dom.php sets from ResultSetManager::CONSTRAINT_OPERATORS on every
 * page; ConstraintOperatorsJsTest checks that it does, and in this order.
 */
const SERVER_OPERATORS = ['==', '!=', '>=', '<=', '>', '<', '=~', '!~', '=@', '!@'];

beforeEach(() => {
    OWA.setSetting('constraint_operators', SERVER_OPERATORS);
});

/** What the grid reads from one clause, in the pills' shape. */
function asTheGridReadsIt(clause) {
    const [c] = OWA.dataGrid.parseConstraints(clause);

    return { name: c.name, op: c.operator, value: c.value };
}

describe('a stored clause', () => {

    test.each([
        ['sessions>=5',          { name: 'sessions',  op: '>=', value: '5' }],
        ['sessions<=5',          { name: 'sessions',  op: '<=', value: '5' }],
        ['pagePath=~^/blog/',    { name: 'pagePath',  op: '=~', value: '^/blog/' }],
        ['pagePath!~^/blog/',    { name: 'pagePath',  op: '!~', value: '^/blog/' }],
        ['pagePath!@/blog/',     { name: 'pagePath',  op: '!@', value: '/blog/' }],
        // =@ is one of the form's, but the server reads >= first.
        ['pageTitle=@a>=b',      { name: 'pageTitle=@a', op: '>=', value: 'b' }],
        ['pagePath=@/blog/',     { name: 'pagePath',  op: '=@', value: '/blog/' }],
        ['browserType==Chrome',  { name: 'browserType', op: '==', value: 'Chrome' }],
        ['pageViews>50',         { name: 'pageViews', op: '>',  value: '50' }],
        ['pageTitle==a>b',       { name: 'pageTitle', op: '==', value: 'a>b' }],
    ])('%s splits where the server and the grid split it', (clause, expected) => {

        expect(OWA.filterPills.split(clause)).toEqual(expected);
        expect(OWA.filterPills.split(clause)).toEqual(asTheGridReadsIt(clause));
    });

    test('round-trips through parse() and stringify() unchanged', () => {

        const str = 'sessions>=5,pagePath=~^/blog/,pagePath!@/tag/,medium!=(not set)';

        expect(OWA.filterPills.stringify(OWA.filterPills.parse(str))).toBe(str);
    });

    test('an empty test keeps (not set) as part of its operator', () => {

        expect(OWA.filterPills.split('medium==(not set)')).toEqual({ name: 'medium', op: '==(not set)', value: '' });
        expect(OWA.filterPills.split('source!=(not set)')).toEqual({ name: 'source', op: '!=(not set)', value: '' });
    });

    test('name and value are trimmed', () => {

        expect(OWA.filterPills.split(' sessions >= 5 ')).toEqual({ name: 'sessions', op: '>=', value: '5' });
    });
});

describe('the operators', () => {

    test('are the ones the page was given, read when a clause is split', () => {

        OWA.setSetting('constraint_operators', ['>', '>=']);

        expect(OWA.filterPills.split('sessions>=5')).toEqual({ name: 'sessions', op: '>', value: '=5' });
    });
});
