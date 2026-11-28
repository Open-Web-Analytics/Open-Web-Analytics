// owa.js imports jQuery as a namespace and calls it, and the builders import
// it as the default. Webpack makes both callable; babel-jest's interop does
// only when jQuery is marked an ES module and is its own default.
jest.mock('jquery', () => {
    const jq = jest.requireActual('jquery');
    jq.__esModule = true;
    jq.default = jq;
    return jq;
});

import { readStep, isBlankStep, renumber, bindStep, STEP_CLASSES, DEFAULT_EVENT }
    from '../../modules/Base/src/reporting/owa.funnelbuilder.js';
import { CUSTOM_TRIGGER } from '../../modules/Base/src/reporting/owa.goalbuilder.js';

const $ = require('jquery');

/**
 * The funnel builder's steps (owa.funnelbuilder.js): every step is an event and
 * conditions on it, read back the way the save reads the form, with condition
 * rows that follow the chosen event through the goal event definition form's
 * condition builder (owa.goalbuilder.js).
 */

const VOCAB = {
    page_view: [
        { name: 'page_path', label: 'Page path', description: 'The path.', carried: true },
        { name: 'page_title', label: 'Page title', description: '', carried: true },
        { name: 'cd_plan', label: 'Plan', description: 'Custom dimension.', carried: true },
    ],
    click: [
        { name: 'page_path', label: 'Page path', description: 'The path.', carried: true },
        { name: 'element_id', label: 'Element id', description: '', carried: true },
        { name: 'cd_plan', label: 'Plan', description: 'Custom dimension.', carried: true },
    ],
    [CUSTOM_TRIGGER]: [
        { name: 'page_path', label: 'Page path', description: 'The path.', carried: true },
    ],
};

/** One step row as visualization_edit_step.php draws a new one: page_view, page_path, nothing typed. */
function stepRow(index = 0) {
    const li = document.createElement('li');
    li.className = 'owa_funnelStep';
    li.innerHTML = `
        <select class="owa_funnelStepEvent" name="owa_stepEvent[]" aria-label="The event this step matches">
            <option value="page_view" data-label="page_view" data-group="built_in" data-group-label="Built-in events"
                    data-description="A page was viewed." selected>page_view</option>
            <option value="click" data-label="click" data-group="built_in" data-group-label="Built-in events"
                    data-description="An element was clicked.">click</option>
            <option value="newsletter_signup" data-label="newsletter_signup" data-group="yours" data-group-label="Custom events"
                    data-description="">newsletter_signup</option>
            <option value="${CUSTOM_TRIGGER}" data-label="Another custom event" data-group="yours" data-group-label="Custom events"
                    data-description="">Another custom event</option>
        </select>
        <input class="owa_funnelStepCustomEvent" type="text" name="owa_stepCustomEvent[]" value="" hidden>
        <select class="owa_funnelStepMatch" name="owa_stepMatch[]">
            <option value="all" selected>all</option><option value="any">any</option>
        </select>
        <ul class="constraintList owa_funnelConditions" data-owa-repeatable>
            <li class="constraintRow owa_funnelCondition" data-saved-property="page_path">
                <select class="owa_funnelConditionProperty" name="owa_stepConditionProperty[${index}][]">
                    <option value="page_path" selected>Page path</option>
                </select>
                <select class="owa_funnelConditionOperator" name="owa_stepConditionOperator[${index}][]">
                    <option value="exact" selected>Exactly matching</option><option value="regex">Matches regex</option>
                </select>
                <input class="owa_funnelConditionValue" type="text" name="owa_stepConditionValue[${index}][]" value="">
                <div class="owa_funnelConditionHelp"></div>
            </li>
        </ul>
        <input class="owa_funnelStepName" type="hidden" name="owa_stepName[]" value="">`;

    document.body.appendChild(li);

    return li;
}

beforeAll(() => {
    const vocab = document.createElement('script');
    vocab.type = 'application/json';
    vocab.id = 'owa_funnelVocabulary';
    vocab.textContent = JSON.stringify(VOCAB);
    document.body.appendChild(vocab);
});

afterEach(() => {
    document.querySelectorAll('.owa_funnelStep').forEach((el) => el.remove());
});

test('a new step is a page view on its path, and blank until something is typed', () => {
    const row = stepRow();
    const step = readStep(row);

    expect(DEFAULT_EVENT).toBe('page_view');
    expect(step).toEqual({ name: '', event: 'page_view', custom: false, match: 'all', conditions: [] });
    expect(isBlankStep(step)).toBe(true);

    row.querySelector('.owa_funnelConditionValue').value = ' /pricing ';

    expect(readStep(row).conditions).toEqual([{ property: 'page_path', operator: 'exact', value: '/pricing' }]);
    expect(isBlankStep(readStep(row))).toBe(false);
});

test('another event with no conditions is a step: it matches every one', () => {
    const row = stepRow();

    row.querySelector('.owa_funnelStepEvent').value = 'click';

    expect(isBlankStep(readStep(row))).toBe(false);
});

test('"another custom event" is named by the field beside it', () => {
    const row = stepRow();

    row.querySelector('.owa_funnelStepEvent').value = CUSTOM_TRIGGER;
    row.querySelector('.owa_funnelStepCustomEvent').value = ' newsletter_signup ';
    row.querySelector('.owa_funnelStepMatch').value = 'any';

    expect(readStep(row)).toMatchObject({ event: 'newsletter_signup', custom: true, match: 'any' });

    row.querySelector('.owa_funnelStepCustomEvent').value = '';

    const unnamed = readStep(row);
    expect(unnamed.event).toBe('');
    expect(isBlankStep(unnamed)).toBe(false);
});

test('a step\'s condition fields are named by its position', () => {
    const row = stepRow(0);

    renumber(row, 3);

    expect([...row.querySelectorAll('[name*="stepCondition"]')].map((f) => f.name)).toEqual([
        'owa_stepConditionProperty[3][]',
        'owa_stepConditionOperator[3][]',
        'owa_stepConditionValue[3][]',
    ]);

    // The step's own fields are parallel arrays, unnumbered.
    expect(row.querySelector('.owa_funnelStepEvent').name).toBe('owa_stepEvent[]');
});

test('the condition rows offer the chosen event\'s properties, and follow it', () => {
    const row = stepRow();
    const property = () => row.querySelector('.' + STEP_CLASSES.property);
    const offered = () => [...property().options].map((o) => o.value);

    bindStep(row);

    expect(offered()).toEqual(['page_path', 'page_title', 'cd_plan']);
    expect(property().value).toBe('page_path');
    expect(row.querySelector('.owa_funnelConditionHelp').textContent).toBe('The path.');

    const event = row.querySelector('.owa_funnelStepEvent');
    event.value = 'click';
    $(event).trigger('change');

    expect(offered()).toEqual(['page_path', 'element_id', 'cd_plan']);
    expect(property().value).toBe('page_path');

    // The custom name field shows for "another custom event" only.
    expect(row.querySelector('.owa_funnelStepCustomEvent').hidden).toBe(true);
    event.value = CUSTOM_TRIGGER;
    $(event).trigger('change');
    expect(row.querySelector('.owa_funnelStepCustomEvent').hidden).toBe(false);
    expect(offered()).toEqual(['page_path']);
});

test('a saved property the new event does not carry is kept and flagged', () => {
    const row = stepRow();

    bindStep(row);

    property(row).value = 'page_title';
    row.querySelector('.owa_funnelCondition').dataset.savedProperty = 'page_title';

    const event = row.querySelector('.owa_funnelStepEvent');
    event.value = 'click';
    $(event).trigger('change');

    expect(property(row).value).toBe('page_title');
    expect(row.querySelector('.owa_funnelConditionHelp').classList.contains(STEP_CLASSES.warning)).toBe(true);
});

test('a condition\'s property is picked in the field chooser, from the button after its select', () => {
    const row = stepRow();

    bindStep(row);

    const button = row.querySelector('.' + STEP_CLASSES.button);

    expect(property(row).hidden).toBe(true);
    expect(button.textContent.trim()).toBe('Page path');

    $(button).trigger('click');
    $('.owa_fieldChooser .owa_fieldSearch').val('plan').trigger('input');
    $('.owa_fieldChooser .owa_fieldOption[data-name="cd_plan"]').trigger('click');

    expect(property(row).value).toBe('cd_plan');
    expect(button.textContent.trim()).toBe('Plan');
    expect(row.querySelector('.owa_funnelConditionHelp').textContent).toBe('Custom dimension.');
    expect($('.owa_fieldChooser')).toHaveLength(0);
});

test('the event is picked in the field chooser: built-in events and custom events, each described', () => {
    const row = stepRow();

    bindStep(row);

    const event = row.querySelector('.' + STEP_CLASSES.trigger);
    const button = row.querySelector('.' + STEP_CLASSES.triggerButton);

    expect(event.hidden).toBe(true);
    expect(button.previousElementSibling).toBe(event);
    expect(button.getAttribute('aria-label')).toBe('The event this step matches');
    expect(button.textContent.trim()).toBe('page_view');

    $(button).trigger('click');

    expect($('.owa_fieldGroup .owa_fieldLabel').map((i, e) => $(e).text()).get()).toEqual(['Built-in events', 'Custom events']);

    $('.owa_fieldGroup').first().trigger('click');
    $('.owa_fieldChooser .owa_fieldOption[data-name="click"]').trigger('mousemove');
    expect($('.owa_fieldChooser .owa_fieldDescription').text()).toContain('An element was clicked.');

    $('.owa_fieldChooser .owa_fieldOption[data-name="click"]').trigger('click');

    expect(event.value).toBe('click');
    expect(button.textContent.trim()).toBe('click');
    expect($('.owa_fieldChooser')).toHaveLength(0);

    // The conditions follow the event, as they do when the <select> changes.
    expect(Array.from(property(row).options).map((o) => o.value)).toContain('element_id');

    // "Another custom event" shows the name field.
    $(button).trigger('click');
    $('.owa_fieldGroup').eq(1).trigger('click');
    $('.owa_fieldChooser .owa_fieldOption[data-name="' + CUSTOM_TRIGGER + '"]').trigger('click');
    expect(row.querySelector('.owa_funnelStepCustomEvent').hidden).toBe(false);
});

function property(row) {
    return row.querySelector('.' + STEP_CLASSES.property);
}

/**
 * A condition's + and X are buttons, so Enter and Space press them and no
 * other key does anything.
 */
describe('a repeatable list\'s + and X', () => {

    const fs = require('fs');
    const path = require('path');

    function list() {
        document.body.insertAdjacentHTML('beforeend', `
            <ul class="constraintList" id="repeat" data-owa-repeatable>
                <li class="constraintRow"><input class="constraintValueField" value="a">
                    <button type="button" class="constraintAddButton">+</button>
                    <button type="button" class="constraintRemoveButton">X</button></li>
            </ul>`);
        return document.getElementById('repeat');
    }

    afterEach(() => {
        const ul = document.getElementById('repeat');
        if (ul) {
            ul.remove();
        }
    });

    test('a click adds a blank row after its own, and removes a row', async () => {
        await new Promise((ready) => $(ready));
        const ul = list();

        $(ul).find('.constraintAddButton').first().trigger('click');
        expect(ul.querySelectorAll('.constraintRow')).toHaveLength(2);
        expect(ul.querySelectorAll('.constraintRow')[1].querySelector('input').value).toBe('');

        $(ul).find('.constraintRemoveButton').last().trigger('click');
        expect(ul.querySelectorAll('.constraintRow')).toHaveLength(1);
    });

    test('a key that is not a click does nothing', async () => {
        await new Promise((ready) => $(ready));
        const ul = list();
        const add = ul.querySelector('.constraintAddButton');

        for (const key of ['a', 'Tab', 'ArrowDown', 'Enter', ' ']) {
            $(add).trigger($.Event('keypress', { key, which: key === 'Enter' ? 13 : key.charCodeAt(0) }));
            $(add).trigger($.Event('keydown', { key }));
        }

        expect(ul.querySelectorAll('.constraintRow')).toHaveLength(1);
    });

    test.each([
        'modules/Base/templates/goal_event_definition_edit.php',
        'modules/Base/templates/visualization_edit_step.php',
    ])('%s draws them as buttons', (file) => {
        const src = fs.readFileSync(path.join(__dirname, '../..', file), 'utf8');

        expect(src).toMatch(/<button type="button" class="constraintAddButton"/);
        expect(src).toMatch(/<button type="button" class="constraintRemoveButton"/);
        expect(src).not.toMatch(/<span class="constraint(Add|Remove)Button"/);
    });
});
