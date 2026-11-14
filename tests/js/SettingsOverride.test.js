import '../../modules/Base/src/reporting/v1/owa.settingsoverride.js';

/**
 * The Override switch beside a setting rendered below the install
 * (SettingsForm::scopedField): on hides the inherited text and shows the
 * control, editable and focused; off puts the inherited value back, hides and
 * disables the control and shows the text again. The notes and the switch's
 * description follow.
 */

const ID = 'owa-setting-base-flag';

function render(checked) {
    document.body.innerHTML = `
        <label class="title" id="${ID}-title" for="${ID}">Flag</label>
        <div class="field owa-overridable">
            <div class="owa-overridable__value">
                <select name="config[base.flag]" id="${ID}" data-owa-inherited="1"${checked ? '' : ' disabled hidden'}>
                    <option value="0"${checked ? ' selected' : ''}>Off</option>
                    <option value="1"${checked ? '' : ' selected'}>On</option>
                </select>
                <output id="${ID}-inherited" aria-labelledby="${ID}-title" data-ui="setting-inherited"${checked ? ' hidden' : ''}>On</output>
                <span id="${ID}-note-inherit" data-owa-note-inherit="${ID}"${checked ? ' hidden' : ''}>Set at the install level.</span>
            </div>
            <label><input type="checkbox" role="switch" data-owa-override="${ID}" data-ui="setting-override" aria-label="Override Flag"
                aria-describedby="${checked ? `${ID}-note-override` : `${ID}-inherited ${ID}-note-inherit`}"${checked ? ' checked' : ''}> Override</label>
        </div>
        <div id="${ID}-note-override" data-owa-note-override="${ID}"${checked ? '' : ' hidden'}>Overrides the install level value of <code>On</code>.</div>`;

    return {
        control: document.getElementById(ID),
        sw: document.querySelector('[data-owa-override]'),
        text: document.querySelector('[data-ui="setting-inherited"]'),
        inherit: document.querySelector('[data-owa-note-inherit]'),
        override: document.querySelector('[data-owa-note-override]'),
    };
}

function flip(sw) {
    sw.checked = !sw.checked;
    sw.dispatchEvent(new Event('change', { bubbles: true }));
}

test('switching on hides the text and shows the control, editable and focused', () => {
    const el = render(false);

    flip(el.sw);

    expect(el.text.hidden).toBe(true);
    expect(el.control.hidden).toBe(false);
    expect(el.control.disabled).toBe(false);
    expect(el.control.value).toBe('1');
    expect(document.activeElement).toBe(el.control);
    expect(el.inherit.hidden).toBe(true);
    expect(el.override.hidden).toBe(false);
    expect(el.sw.getAttribute('aria-describedby')).toBe(`${ID}-note-override`);
});

test('switching off restores the inherited value, hides and disables the control, and shows the text', () => {
    const el = render(true);
    expect(el.control.value).toBe('0');

    flip(el.sw);

    expect(el.control.disabled).toBe(true);
    expect(el.control.hidden).toBe(true);
    expect(el.control.value).toBe('1');
    expect(el.text.hidden).toBe(false);
    expect(el.inherit.hidden).toBe(false);
    expect(el.override.hidden).toBe(true);
    expect(el.sw.getAttribute('aria-describedby')).toBe(`${ID}-inherited ${ID}-note-inherit`);
});

test('a value chosen while on is replaced by the inherited one when switched off', () => {
    const el = render(false);

    flip(el.sw);
    el.control.value = '0';
    flip(el.sw);

    expect(el.control.value).toBe('1');
    expect(el.text.textContent).toBe('On');
});

test('a refused post redrawn with the switch on shows the control until switched off', () => {
    const el = render(true);

    expect(el.control.hidden).toBe(false);
    expect(el.control.disabled).toBe(false);
    expect(el.text.hidden).toBe(true);

    flip(el.sw);

    expect(el.control.hidden).toBe(true);
    expect(el.text.hidden).toBe(false);
});

test('a switch naming no control is ignored', () => {
    document.body.innerHTML = '<input type="checkbox" data-owa-override="missing">';
    const sw = document.querySelector('input');

    expect(() => flip(sw)).not.toThrow();
});
