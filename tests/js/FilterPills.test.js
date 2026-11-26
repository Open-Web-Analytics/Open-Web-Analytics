// The pills import jQuery as a namespace and call it. Webpack makes that
// callable; babel-jest's interop does only when jQuery is marked an ES module.
jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    return jq;
});

import { OWA } from '../../modules/Base/src/reporting/owa.format.js';

const $ = require('jquery');

global.jQuery = global.$ = $;

require('../../modules/Base/src/reporting/owa.resultSetExplorer.js');
require('../../modules/Base/src/reporting/owa.fieldchooser.js');
require('../../modules/Base/src/reporting/owa.filterpills.js');

/**
 * The filter pills (OWA.filterPills): how a stored constraints string is read
 * back into pills, and how a pill is added, changed and removed.
 *
 * A clause is split where the server splits it, by the report grid's reader
 * (OWA.dataGrid.parseClause()), so a clause using an operator the form does not
 * offer comes back whole.
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

    test('a value is escaped as the server reads it', () => {

        const f = [{ name: 'pagePath', op: '==', value: '/a,b%' }];

        expect(OWA.filterPills.stringify(f)).toBe('pagePath==/a%2Cb%25');
        expect(OWA.filterPills.parse(OWA.filterPills.stringify(f))).toEqual(f);
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

/* The pill and the grid's chip are one sentence: the pills call the grid's. */
test.each([
    'sessions>=5', 'pagePath=@/blog/', 'medium==(not set)', 'source!=(not set)', 'pagePath==',
])('the pill for %s reads as the grid chip does', (clause) => {
    const [c] = OWA.dataGrid.parseConstraints(clause);

    expect(OWA.filterPills.words(asTheGridReadsIt(clause))).toBe(OWA.dataGrid.filterWords(c));
});

test('an operator with no value reads without one, on a pill as on a chip', () => {
    expect(OWA.filterPills.words({ name: 'pagePath', op: '==', value: '' })).toBe('pagePath is');
    expect(OWA.filterPills.sentence({ name: 'pagePath', op: '==', value: '' })).toEqual(['pagePath is']);
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
        return page([{ name: 'medium', label: 'Medium', type: 'string' }]);
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
        OWA.config.strings = { 'Contains': 'Contient', 'Has a value': 'A une valeur' };

        pills().open();
        pick('medium');

        const labels = $('#o option').map((i, o) => $(o).text()).get();

        expect(labels).toContain('Contient');
        expect(labels).toContain('A une valeur');
        expect($('#o option[value="=@"]').text()).toBe('Contient');
    });
});

/** The page the pills are drawn on, as filter_pills.php writes it. */
function page(dimensions, onChange = () => {}) {
    document.body.innerHTML = `
        <div id="p"></div><button id="a"></button>
        <div id="f"><button type="button" id="d"></button><select id="o"></select><label for="v">Value</label><input id="v">
            <button id="c">Cancel</button><button id="af">Add filter</button></div>`;

    return new OWA.filterPills(
        { pills: 'p', add: 'a', form: 'f', dimension: 'd', operator: 'o', value: 'v', addFilter: 'af', cancel: 'c' },
        dimensions || [
            { name: 'medium', label: 'Medium', group: 'traffic source', type: 'string' },
            { name: 'source', label: 'Source', group: 'traffic source', type: 'string' },
            { name: 'isOutbound', label: 'Outbound Click', group: 'event', type: 'boolean' },
            { name: 'date', label: 'Date', group: 'time', type: 'yyyymmdd' },
            { name: 'pageDepth', label: 'Page Depth', group: 'visit', type: 'integer' },
        ],
        onChange);
}

/** Pick a field in the form's chooser, as a person does: open it, search, click the name. */
function pick(name) {
    $('#d').trigger('click');
    $('.owa_fieldChooser .owa_fieldSearch').val(name).trigger('input');
    $('.owa_fieldChooser .owa_fieldOption[data-name="' + name + '"]').trigger('click');
}

describe('the form', () => {

    afterEach(() => {
        document.body.innerHTML = '';
    });

    test('offers a text field its conditions, worded as the grid words them', () => {
        page().open();
        pick('medium');

        expect($('#o option').map((i, o) => [[o.value, $(o).text()]]).get()).toEqual([
            ['=@', 'Contains'],
            ['==', 'Is'],
            ['!=', 'Is not'],
            ['!=(not set)', 'Has a value'],
            ['==(not set)', 'Is not set'],
        ]);
        expect($('#d').text()).toBe('Medium');
    });

    test('a Yes/No field is Yes or No, never free text', () => {
        const p = page();

        p.open();
        pick('isOutbound');

        expect($('#o option').map((i, o) => o.value).get()).toEqual(['==']);
        expect($('#v').attr('role')).toBe('radiogroup');

        $('#v input[value="No"]').prop('checked', true).trigger('change');
        $('#af').trigger('click');

        expect(p.get()).toBe('isOutbound==No');
        expect($('#p .owa_filterPillEdit').text()).toBe('Outbound Click is No');
    });

    test('a date is picked as a date and stored as yyyymmdd, and compared before or after', () => {
        const p = page();

        p.open();
        pick('date');

        expect($('#v').attr('type')).toBe('date');
        expect($('#o option').map((i, o) => $(o).text()).get()).toEqual(['Is', 'Before', 'After']);

        $('#o').val('<').trigger('change');
        $('#v').val('2026-11-01').trigger('input');
        $('#af').trigger('click');

        expect(p.get()).toBe('date<20261101');
        expect($('#p .owa_filterPillEdit').text()).toBe('Date before 20261101');

        $('#p .owa_filterPillEdit').trigger('click');
        expect($('#v').val()).toBe('2026-11-01');
    });

    test('a number is a number box', () => {
        page().open();
        pick('pageDepth');

        expect($('#v').attr('type')).toBe('number');
        expect($('#o option').map((i, o) => o.value).get()).toEqual(['==', '!=', '>', '<']);
    });

    test('lists dimensions and then metrics, each under its group', () => {
        const p = page(OWA.filterPills.choices(
            { 'traffic source': [{ name: 'medium', label: 'Medium', group_label: 'Traffic Source' }], site: [{ name: 'siteId', label: 'Profile' }] },
            { 'Site Usage': [{ name: 'pageViews', label: 'Page Views', metric_type: 'count' }] }));

        p.open();
        $('#d').trigger('click');

        expect($('.owa_fieldChooser').children().first().find('.owa_fieldHeading, .owa_fieldGroup .owa_fieldLabel').map((i, e) => $(e).text()).get())
            .toEqual(['Dimensions', 'Traffic Source', 'site', 'Metrics', 'Site Usage']);
    });
});

describe('a pill', () => {

    afterEach(() => {
        document.body.innerHTML = '';
    });

    test('opens the form on its filter, which Update filter changes in place', () => {
        const changes = [];
        const p = page(undefined, () => changes.push(p.get()));

        p.set('medium==organic,source=@news');
        $('#p .owa_filterPill').eq(0).find('.owa_filterPillEdit').trigger('click');

        expect($('#d').text()).toBe('Medium');
        expect($('#o').val()).toBe('==');
        expect($('#v').val()).toBe('organic');
        expect($('#af').text()).toBe('Update filter');
        expect($('#p .owa_filterPillEdit').eq(0).attr('aria-expanded')).toBe('true');

        // jsdom lays nothing out, so nothing is :visible.
        p.isOpen = () => true;
        expect(p.problem()).toBe('Update the filter you are changing, or cancel it.');
        delete p.isOpen;

        $('#o').val('!=').trigger('change');
        $('#af').trigger('click');

        expect(p.get()).toBe('medium!=organic,source=@news');
        expect(changes[changes.length - 1]).toBe('medium!=organic,source=@news');
        expect($('#af').text()).toBe('Add filter');
        expect($('#p .owa_filterPill')).toHaveLength(2);
    });

    test('names the filter it changes, and its X the filter it removes', () => {
        const p = page();

        p.set('medium==organic');

        expect($('#p .owa_filterPillEdit').attr('aria-label')).toBe('Change the filter Medium is organic');
        expect($('#p .owa_filterPillEdit').attr('aria-controls')).toBe('f');
        expect($('#p .owa_filterPillRemove').attr('aria-label')).toBe('Remove the filter Medium is organic');
    });

    test('a stored Yes/No value reads as Yes or No, however it was written', () => {
        const p = page();

        p.set('isOutbound==1');

        expect($('#p .owa_filterPillEdit').text()).toBe('Outbound Click is Yes');
    });

    test('keeps a stored operator the form does not offer, and a name it does not list', () => {
        const p = page();

        p.set('pageViews>=5');
        $('#p .owa_filterPillEdit').trigger('click');

        expect($('#d').text()).toBe('pageViews');
        expect($('#o').val()).toBe('>=');
        expect($('#o option[value=">="]').text()).toBe('at least');

        $('#af').trigger('click');

        expect(p.get()).toBe('pageViews>=5');

        // A new filter is not offered the stored operator.
        p.open();

        expect($('#o option[value=">="]')).toHaveLength(0);
    });

    test('changed to an empty test drops its value', () => {
        const p = page();

        p.set('medium==organic');
        $('#p .owa_filterPillEdit').trigger('click');
        $('#o').val('==(not set)').trigger('change');
        $('#af').trigger('click');

        expect(p.get()).toBe('medium==(not set)');
    });

    test('Filter, while a pill is being changed, starts a new filter', () => {
        const p = page();

        p.set('medium==organic');
        $('#p .owa_filterPillEdit').trigger('click');
        $('#a').trigger('click');

        expect($('#d .owa_fieldTriggerText').hasClass('is-placeholder')).toBe(true);
        expect($('#af').text()).toBe('Add filter');

        pick('source');
        $('#o').val('==').trigger('change');
        $('#v').val('news').trigger('input');
        $('#af').trigger('click');

        expect(p.get()).toBe('medium==organic,source==news');
    });

    test("removed by its X, the others keep their place", () => {
        const p = page();

        p.set('medium==organic,source=@news,medium!=(not set)');
        $('#p .owa_filterPill').eq(1).find('.owa_filterPillRemove').trigger('click');

        expect(p.get()).toBe('medium==organic,medium!=(not set)');
    });
});

test('choices() reads lists by group as chooser fields, and an empty list as nothing', () => {
    expect(OWA.filterPills.choices({ a: [{ name: 'x', label: 'X', description: 'd', group_label: 'A', data_type: 'string' }] }, [], null)).toEqual([
        { name: 'x', label: 'X', description: 'd', group: 'a', group_label: 'A', type: 'string', kind: 'dimension' },
    ]);
});
