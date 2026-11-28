import { optionsFor, helpText, fill, fieldsOf, setText, triggerName, CUSTOM_TRIGGER } from '../../modules/Base/src/reporting/owa.goalbuilder.js';

/**
 * The goal event definition builder's picker (owa.goalbuilder.js): what it
 * offers for an event, keeping a property already in use, and how an option
 * reads.
 */

const VOCAB = {
    page_view: [
        { name: 'page_path', label: 'Page path', description: 'The path.', carried: true, group: 'content', group_label: 'Content' },
        { name: 'tagged_source', label: 'Source (from the URL)', description: 'The tagged source.', carried: true, group: 'traffic source', group_label: 'Traffic Source' },
    ],
    click: [
        { name: 'page_path', label: 'Page path', description: 'The path.', carried: true, group: 'content', group_label: 'Content' },
        { name: 'is_outbound', label: 'Outbound click', description: 'Whether it left.', carried: true, group: 'event', group_label: 'Event' },
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
    expect(kept.group_label).toBe('Traffic Source');
});

/*
 * A site's own event: every one carries the common properties, listed once
 * under the picker's custom value, which any name the vocabulary does not have
 * falls back to.
 */
test('an event of the site\'s own offers the custom list', () => {
    const vocab = { ...VOCAB, [CUSTOM_TRIGGER]: [ { name: 'page_path', label: 'Page path', description: '', carried: true } ] };

    expect(optionsFor(vocab, 'newsletter_signup', '').map((p) => p.name)).toEqual(['page_path']);
    expect(optionsFor(vocab, CUSTOM_TRIGGER, '').map((p) => p.name)).toEqual(['page_path']);
    expect(optionsFor(vocab, 'click', '').map((p) => p.name)).toEqual(['page_path', 'is_outbound']);
});

test('the trigger is named by the field beside "another custom event"', () => {
    expect(triggerName('click', 'ignored')).toBe('click');
    expect(triggerName(CUSTOM_TRIGGER, '  newsletter_signup ')).toBe('newsletter_signup');
    expect(triggerName(CUSTOM_TRIGGER, '')).toBe('custom');
});

test('a carried property in use is not duplicated', () => {
    expect(optionsFor(VOCAB, 'click', 'is_outbound').filter((p) => p.name === 'is_outbound')).toHaveLength(1);
});

test('the help line describes the property, or says why a flagged one cannot match', () => {
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

test('an option is the label alone, and the chooser reads its description and group from it', () => {
    const select = document.createElement('select');

    fill(select, optionsFor(VOCAB, 'click', ''), 'is_outbound');

    expect(select.options[1].text).toBe('Outbound click');
    expect(fieldsOf(select)[1]).toEqual({
        name: 'is_outbound', label: 'Outbound click', description: 'Whether it left.',
        group: 'event', group_label: 'Event', type: '', kind: 'dimension',
    });
});

test('the page\'s translated patterns replace the English ones', () => {
    setText({ notCarriedLabel: '%s (sin %s)', notCarriedHelp: 'Un evento %s no lo lleva.' });

    expect(optionsFor(VOCAB, 'click', 'tagged_source').find((p) => p.name === 'tagged_source').label)
        .toBe('Source (from the URL) (sin click)');
    expect(helpText({ name: 'x', label: 'X', carried: false }, 'click')).toBe('Un evento click no lo lleva.');

    setText({ notCarriedLabel: '%s -- not carried by %s', notCarriedHelp: 'A %s event does not carry this property.' });
});
