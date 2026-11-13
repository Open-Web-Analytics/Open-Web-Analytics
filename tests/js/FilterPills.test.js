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

/*
 * A stored clause may use an operator the form does not offer. Its pill reads
 * as the grid's chip says it, not as the raw operator.
 */
test('a pill for an operator the form does not offer reads in words', () => {
    expect(OWA.filterPills.words({ name: 'sessions', op: '>=', value: '5' })).toBe('sessions at least 5');
    expect(OWA.filterPills.words({ name: 'pagePath', op: '=~', value: '^/blog' })).toBe('pagePath matches ^/blog');
    expect(OWA.filterPills.words({ name: 'pagePath', op: '=@', value: 'blog' })).toBe('pagePath contains blog');
    expect(OWA.filterPills.words({ name: 'medium', op: '==(not set)', value: '' })).toBe('medium is not set');
});

/**
 * A pill is the grid's chip sentence, and its condition labels are the
 * catalogued table labels (Strings::LABEL_TABLES), so a translation reaches
 * both the pill and the form's condition list.
 */
describe('in a translated locale', () => {

    afterEach(() => {
        delete OWA.config.strings;
        document.body.innerHTML = '';
    });

    function pills() {
        document.body.innerHTML = `
            <div id="p"></div><button id="a"></button>
            <div id="f"><select id="d"></select><select id="o"></select><input id="v"><button id="af"></button></div>`;
        $.fn.chosen = function () { return this; };

        return new OWA.filterPills(
            { pills: 'p', add: 'a', form: 'f', dimension: 'd', operator: 'o', value: 'v', addFilter: 'af', cancel: 'c' },
            [{ name: 'medium', label: 'Medium' }],
            () => {});
    }

    test('the words follow the translated sentence and condition', () => {
        OWA.config.strings = {
            'filter chip: field, condition, value\x04%1$s %2$s %3$s': '%3$s : %1$s (%2$s)',
            'filter chip: field, condition\x04%1$s %2$s': '%1$s — %2$s',
            'at least': 'au moins',
            'is not set': "n'est pas défini",
        };

        expect(OWA.filterPills.words({ name: 'sessions', op: '>=', value: '5' })).toBe('5 : sessions (au moins)');
        expect(OWA.filterPills.words({ name: 'medium', op: '==(not set)', value: '' })).toBe("medium — n'est pas défini");
    });

    test('a pill draws the value in bold where the sentence puts it, and its X names the filter', () => {
        OWA.config.strings = {
            'filter chip: field, condition, value\x04%1$s %2$s %3$s': '%3$s : %1$s (%2$s)',
            'is': 'est',
            'Remove the filter %s': 'Retirer le filtre %s',
        };

        const p = pills();
        p.set('medium==organic');

        const $pill = $('#p .owa_filterPill');

        expect($pill.text()).toBe('organic : Medium (est)');
        expect($pill.find('strong').text()).toBe('organic');
        expect($pill.find('.owa_filterPillRemove').attr('aria-label')).toBe('Retirer le filtre organic : Medium (est)');
    });

    test('the form offers its conditions translated', () => {
        OWA.config.strings = { 'contains': 'contient', 'is set': 'est défini' };

        pills();

        const labels = $('#o option').map((i, o) => $(o).text()).get();

        expect(labels).toContain('contient');
        expect(labels).toContain('est défini');
        expect($('#o option[value="=@"]').text()).toBe('contient');
    });
});
