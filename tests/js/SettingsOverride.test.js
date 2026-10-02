import '../../modules/Base/src/reporting/v1/owa.settingsoverride.js';

/**
 * The Override switch beside a setting rendered below the install
 * (SettingsForm::scopedField): on makes the control editable, off puts the
 * inherited value back and disables it, and the notes beneath follow.
 */

function render(checked) {
    document.body.innerHTML = `
        <select name="config[base.flag]" id="owa-setting-base-flag" data-owa-inherited="1"${checked ? '' : ' disabled'}>
            <option value="0"${checked ? ' selected' : ''}>Off</option>
            <option value="1"${checked ? '' : ' selected'}>On</option>
        </select>
        <label><input type="checkbox" data-owa-override="owa-setting-base-flag"${checked ? ' checked' : ''}> Override</label>
        <div data-owa-note-inherit="owa-setting-base-flag"${checked ? ' hidden' : ''}>Currently set at the install level.</div>
        <div data-owa-note-override="owa-setting-base-flag"${checked ? '' : ' hidden'}>Overrides the install level's On.</div>`;

    return {
        control: document.getElementById('owa-setting-base-flag'),
        sw: document.querySelector('[data-owa-override]'),
        inherit: document.querySelector('[data-owa-note-inherit]'),
        override: document.querySelector('[data-owa-note-override]'),
    };
}

function flip(sw) {
    sw.checked = !sw.checked;
    sw.dispatchEvent(new Event('change', { bubbles: true }));
}

test('switching on makes the control editable and swaps the notes', () => {
    const el = render(false);

    flip(el.sw);

    expect(el.control.disabled).toBe(false);
    expect(el.inherit.hidden).toBe(true);
    expect(el.override.hidden).toBe(false);
});

test('switching off restores the inherited value and disables the control', () => {
    const el = render(true);
    expect(el.control.value).toBe('0');

    flip(el.sw);

    expect(el.control.disabled).toBe(true);
    expect(el.control.value).toBe('1');
    expect(el.inherit.hidden).toBe(false);
    expect(el.override.hidden).toBe(true);
});

test('a switch naming no control is ignored', () => {
    document.body.innerHTML = '<input type="checkbox" data-owa-override="missing">';
    const sw = document.querySelector('input');

    expect(() => flip(sw)).not.toThrow();
});
