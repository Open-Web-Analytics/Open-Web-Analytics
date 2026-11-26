// The screen imports jQuery as the default; babel-jest's interop needs jQuery
// marked an ES module and its own default for that to be callable.
jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    jq.default = jq;
    return jq;
});

import { stepsProblem, previewSteps, previewUrl, percent, sayEach, isFullUrl, pathProblem }
    from '../../modules/Base/src/reporting/owa.visualizationEdit.js';
import PATH_CASES from '../fixtures/funnel_path_cases.json';
import { OWA } from '../../modules/Base/src/reporting/owa.format.js';

/**
 * The funnel builder screen's Save gate and preview request
 * (owa.visualizationEdit.js), from steps as owa.funnelbuilder.js reads them.
 */

const blank = () => ({ name: '', event: 'page_view', custom: false, match: 'all', conditions: [] });
const page = (path) => ({ ...blank(), conditions: [{ property: 'page_path', operator: 'exact', value: path }] });
const event = (name) => ({ ...blank(), event: name });

afterEach(() => {
    delete OWA.config.strings;
    delete OWA.config.locale;
    OWA.util.numberFormats = {};
});

describe('what stops a save', () => {

    test('a name first', () => {
        expect(stepsProblem('  ', [page('/a')])).toBe('Give the funnel a name.');
    });

    test('a step is numbered by its row, blank rows included', () => {
        expect(stepsProblem('F', [blank(), event('')])).toBe('Step 2 needs an event.');
        expect(stepsProblem('F', [page('/a'), page('https://example.com/b')]))
            .toBe('Step 2 needs a path, such as /basket, not a full web address.');
    });

    test('a step without an event is named before a later web address', () => {
        expect(stepsProblem('F', [event(''), page('https://example.com/b')])).toBe('Step 1 needs an event.');
    });

    /* The cases FunnelStepValidationTest holds the save to. */
    test.each(PATH_CASES.cases)('a page path, $operator "$value", is refused as the save refuses it ($problem)', (c) => {
        expect(pathProblem({ property: 'page_path', operator: c.operator, value: c.value })).toBe(c.problem);
    });

    test('a query string under exact is stopped before the save', () => {
        expect(stepsProblem('F', [page('/checkout?step=2')]))
            .toBe('Step 1 needs a path without its query string: test the part after the ? as the page query.');
        expect(previewSteps([page('/checkout?step=2')])).toBeNull();
    });

    test('blank rows are not steps', () => {
        expect(stepsProblem('F', [blank(), blank()])).toBe('Add at least one step.');
        expect(stepsProblem('F', [blank(), page('/a')])).toBe('');
    });

    test('the words are translated where the page carries a translation', () => {
        OWA.config.strings = { 'Step %s needs an event.': 'Schritt %s braucht ein Ereignis.' };

        expect(stepsProblem('F', [event('')])).toBe('Schritt 1 braucht ein Ereignis.');
    });
});

describe('the preview', () => {

    test('counts the steps the save keeps, and nothing while one cannot be counted', () => {
        expect(previewSteps([blank(), page('/a'), event('click')])).toEqual([page('/a'), event('click')]);
        expect(previewSteps([page('/a'), event('')])).toBeNull();
        expect(previewSteps([page('mailto://x')])).toBeNull();
        expect(previewSteps([blank()])).toEqual([]);
    });

    test('asks with the steps and the filter added to the page\'s preview link', () => {
        expect(previewUrl('/index.php?owa_do=base.visualizationPreview', [page('/a')], 'browserType==Chrome'))
            .toBe('/index.php?owa_do=base.visualizationPreview&steps='
                + encodeURIComponent(JSON.stringify([page('/a')]))
                + '&funnelFilter=browserType%3D%3DChrome');
    });

    test('a share is a percentage to a tenth, and nothing of nothing is 0%', () => {
        OWA.config.locale = 'en';

        expect(percent(1, 3)).toBe('33.3%');
        expect(percent(0, 0)).toBe('0%');
    });

    test('a share is written in the install\'s locale', () => {
        OWA.config.locale = 'de';

        expect(percent(1, 4).replace(/\s/g, ' ')).toBe('25,0 %');
    });

    test('a web address is told from a path', () => {
        expect(isFullUrl('https://example.com/')).toBe(true);
        expect(isFullUrl('/basket')).toBe(false);
    });

    test('a sentence is filled by position, an unfilled place left as it is', () => {
        expect(sayEach('%1$s then %2$s, %3$s', ['a', 'b'])).toBe('a then b, %3$s');
    });
});
