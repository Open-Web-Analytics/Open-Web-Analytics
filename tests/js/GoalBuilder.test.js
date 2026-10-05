import { optionsFor, optionText, helpText, fill, formatResult, setText, SEPARATOR } from '../../modules/Base/src/reporting/v1/owa.goalbuilder.js';

/**
 * The goal event builder's picker (owa.goalbuilder.js): what it offers for an
 * event, keeping a property already in use, and how an option reads.
 */

const VOCAB = {
    page_view: [
        { name: 'page_path', label: 'Page path', description: 'The path.', carried: true },
        { name: 'tagged_source', label: 'Source (from the URL)', description: 'The tagged source.', carried: true },
    ],
    click: [
        { name: 'page_path', label: 'Page path', description: 'The path.', carried: true },
        { name: 'is_outbound', label: 'Outbound click', description: 'Whether it left.', carried: true },
    ],
};

test('the list is the event\'s own', () => {
    expect(optionsFor(VOCAB, 'click', '').map((p) => p.name)).toEqual(['page_path', 'is_outbound']);
    expect(optionsFor(VOCAB, 'page_view', '').map((p) => p.name)).toEqual(['page_path', 'tagged_source']);
});

test('a property in use the event does not carry is kept, flagged, still described', () => {
    const kept = optionsFor(VOCAB, 'click', 'tagged_source').find((p) => p.name === 'tagged_source');

    expect(kept.carried).toBe(false);
    expect(kept.label).toBe('Source (from the URL) -- not carried by click');
    expect(kept.description).toBe('The tagged source.');
});

test('a carried property in use is not duplicated', () => {
    expect(optionsFor(VOCAB, 'click', 'is_outbound').filter((p) => p.name === 'is_outbound')).toHaveLength(1);
});

test('an option reads label then description; the help line says why a flagged one cannot match', () => {
    expect(optionText(VOCAB.click[1])).toBe('Outbound click' + SEPARATOR + 'Whether it left.');
    expect(helpText(VOCAB.click[1], 'click')).toBe('Whether it left.');
    expect(helpText({ name: 'x', label: 'X', carried: false }, 'click')).toBe('A click event does not carry this property.');
});

test('filling a select keeps the selection where it survives and falls back to the first', () => {
    const select = document.createElement('select');

    fill(select, optionsFor(VOCAB, 'click', 'page_path'), 'page_path');
    expect(select.value).toBe('page_path');
    expect(select.options[1].dataset.label).toBe('Outbound click');
    expect(select.options[1].dataset.description).toBe('Whether it left.');

    fill(select, optionsFor(VOCAB, 'click', ''), 'gone');
    expect(select.value).toBe('page_path');
});

test('an open-list item is split into a name line and a description line, keeping the search match', () => {
    const li = document.createElement('li');
    li.innerHTML = 'Outbound <em>click</em>' + SEPARATOR + 'Whether it left the &amp; site.';

    formatResult(li);

    expect(li.querySelector('.owa_goalOptionName').innerHTML).toBe('Outbound <em>click</em>');
    expect(li.querySelector('.owa_goalOptionDescription').textContent).toBe('Whether it left the & site.');

    // Again is a no-op.
    const before = li.innerHTML;
    formatResult(li);
    expect(li.innerHTML).toBe(before);
});

test('an item with no description is left as it is', () => {
    const li = document.createElement('li');
    li.textContent = 'Page path';
    formatResult(li);
    expect(li.innerHTML).toBe('Page path');
});

test('the page\'s translated patterns replace the English ones', () => {
    setText({ notCarriedLabel: '%s (sin %s)', notCarriedHelp: 'Un evento %s no lo lleva.' });

    expect(optionsFor(VOCAB, 'click', 'tagged_source').find((p) => p.name === 'tagged_source').label)
        .toBe('Source (from the URL) (sin click)');
    expect(helpText({ name: 'x', label: 'X', carried: false }, 'click')).toBe('Un evento click no lo lleva.');

    setText({ notCarriedLabel: '%s -- not carried by %s', notCarriedHelp: 'A %s event does not carry this property.' });
});
