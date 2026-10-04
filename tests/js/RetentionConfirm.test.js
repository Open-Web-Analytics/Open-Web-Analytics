import { readValues, previewParams, previewUrl, decide, UNKNOWN } from '../../modules/Base/src/reporting/v1/owa.retention.js';

/**
 * When a Data Retention save is interrupted to ask, and with what
 * (owa.retention.js). The wording itself is the server's (Retention::preview()).
 */

function installForm(raw, cube) {
    document.body.innerHTML = `
        <form data-owa-retention-form="install" data-owa-retention-preview="/api/index.php?do=retentionPreview">
            <input name="owa_config[base.raw_retention_months]" value="${raw}">
            <input name="owa_config[base.cube_retention_months]" value="${cube}">
        </form>`;
    return document.querySelector('form');
}

function propertyForm(value, overriding) {
    document.body.innerHTML = `
        <form data-owa-retention-form="property" data-owa-retention-property="42">
            <input name="owa_config[base.cube_retention_months]" id="owa-setting-base-cube_retention_months"
                   value="${value}" data-owa-inherited="12"${overriding ? '' : ' disabled'}>
            <input type="checkbox" data-owa-override="owa-setting-base-cube_retention_months"${overriding ? ' checked' : ''}>
        </form>`;
    return document.querySelector('form');
}

test('the install form reads both windows, whatever the namespace prefix', () => {
    expect(readValues(installForm('24', '6'))).toEqual({ raw: '24', cube_default: '6' });
});

test('the property form reads its own window, or that it inherits', () => {
    expect(readValues(propertyForm('6', true))).toEqual({ cube: '6' });
    expect(readValues(propertyForm('12', false))).toEqual({ cube_inherit: '1' });
});

test('with nothing set above, a blank property field inherits', () => {
    document.body.innerHTML = `
        <form data-owa-retention-form="property" data-owa-retention-property="42">
            <input name="config[base.cube_retention_months]" value="">
        </form>`;

    expect(readValues(document.querySelector('form'))).toEqual({ cube_inherit: '1' });
});

test('a save that changes no window is not interrupted', () => {
    expect(previewParams({ raw: '24', cube_default: '0' }, { raw: '24', cube_default: '0' }, '')).toBeNull();
});

test('a changed window asks, naming the Property on its screen', () => {
    expect(previewParams({ raw: '24' }, { raw: '12' }, '')).toEqual({ raw: '12' });
    expect(previewParams({ cube_inherit: '1' }, { cube: '6' }, '42')).toEqual({ cube: '6', property_id: '42' });
});

test('the preview URL keeps what makeApiLink built', () => {
    expect(previewUrl('/api/index.php?do=x&nonce=n', { raw: '12' })).toBe('/api/index.php?do=x&nonce=n&raw=12');
    expect(previewUrl('/api/x', { raw: '12' })).toBe('/api/x?raw=12');
});

test('nothing to ask about submits at once', () => {
    const confirm = jest.fn();
    const submit = jest.fn();

    decide({ needed: false }, confirm, submit);

    expect(confirm).not.toHaveBeenCalled();
    expect(submit).toHaveBeenCalledTimes(1);
});

test('a preview that needs asking asks, and submits only on proceed', () => {
    const submit = jest.fn();
    let asked = null;
    let proceed = null;

    decide({ needed: true, tone: 'danger', title: 'Delete?', paragraphs: ['a', 'b'], proceed: 'Save and delete' },
        (options, go) => { asked = options; proceed = go; }, submit);

    expect(asked).toEqual({ title: 'Delete?', paragraphs: ['a', 'b'], proceed: 'Save and delete', tone: 'danger' });
    expect(submit).not.toHaveBeenCalled();

    proceed();
    expect(submit).toHaveBeenCalledTimes(1);
});

test('no answer from the server is asked about as a warning, not let through', () => {
    expect(UNKNOWN.needed).toBe(true);
    expect(UNKNOWN.tone).toBe('danger');
});
