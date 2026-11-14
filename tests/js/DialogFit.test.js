const $ = require('jquery');

global.jQuery = global.$ = $;
window.jQuery = window.$ = $;

require('jquery-ui-dist/jquery-ui.js');

import { OWA } from '../../modules/Base/src/reporting/v1/owa.js';
import { dialogFit, refitDialogs } from '../../modules/Base/src/reporting/v1/owa.dialog.js';
import '../../modules/Base/src/reporting/v1/owa.confirm.js';

/**
 * A dialog fits the window it opens in (owa.dialog.js): no wider than the
 * window less 16px each side, no taller than it less 8px top and bottom, and
 * on a phone held sideways it sits at the top rather than centred above it.
 */

function windowOf(innerWidth, innerHeight) {
    Object.defineProperty(window, 'innerWidth', { configurable: true, value: innerWidth });
    Object.defineProperty(window, 'innerHeight', { configurable: true, value: innerHeight });
}

afterEach(() => {
    $('.ui-dialog-content').each(function () {
        if ($(this).dialog('instance')) {
            $(this).dialog('destroy');
        }
    });
    document.body.innerHTML = '';
    windowOf(1024, 768);
});

describe('the size and place for a window', () => {

    test.each([
        ['a desktop', 1280, 800, 440, 784],
        ['a phone', 390, 844, 358, 828],
        ['a small phone', 320, 568, 288, 552],
        ['a phone held sideways', 844, 390, 440, 374],
    ])('%s', (name, w, h, width, maxHeight) => {
        const fit = dialogFit(440, { innerWidth: w, innerHeight: h });

        expect(fit.width).toBe(width);
        expect(fit.maxHeight).toBe(maxHeight);
        expect(fit.fitWidth).toBe(440);
    });

    test('centred on a tall window, 8px from the top of a short one', () => {
        const tall = { innerWidth: 390, innerHeight: 844 };
        const short = { innerWidth: 844, innerHeight: 390 };

        expect(dialogFit(760, tall).position).toEqual({ my: 'center', at: 'center', of: tall, collision: 'fit' });
        expect(dialogFit(760, short).position).toEqual({ my: 'center top', at: 'center top+8', of: short, collision: 'none' });
    });

    test('is on the OWA namespace for the templates\' inline scripts', () => {
        expect(OWA.dialogFit).toBe(dialogFit);
    });
});

describe('the confirmation on a phone', () => {

    test('is as wide as the screen less its gutters, and no taller than it', () => {
        windowOf(390, 844);

        OWA.confirmAction({ title: 'Delete this user?', body: 'Gone.' }, () => {});

        const $d = $('#owa_confirmDialog');

        expect($d.attr('data-ui')).toBe('dialog:confirm');
        expect($d.dialog('option', 'width')).toBe(358);
        expect($d.dialog('option', 'maxHeight')).toBe(828);
    });

    test('was 440 wide on a desktop, and still is', () => {
        windowOf(1280, 800);

        OWA.confirmAction({}, () => {});

        expect($('#owa_confirmDialog').dialog('option', 'width')).toBe(440);
    });
});

describe('a dialog open when the window changes', () => {

    test('is fitted again to the new window', () => {
        windowOf(1280, 800);

        const $d = $('<div>').appendTo('body').dialog($.extend({ modal: true }, dialogFit(760)));

        expect($d.dialog('option', 'width')).toBe(760);

        windowOf(390, 844);
        refitDialogs();

        expect($d.dialog('option', 'width')).toBe(358);
        expect($d.dialog('option', 'maxHeight')).toBe(828);
    });

    test('built before it opens, it is fitted to the window it opens in', () => {
        windowOf(1280, 800);

        const $d = $('<div>').appendTo('body').dialog($.extend({ autoOpen: false }, dialogFit(640)));

        windowOf(844, 390);
        $d.dialog('open');

        expect($d.dialog('option', 'width')).toBe(640);
        expect($d.dialog('option', 'maxHeight')).toBe(374);
        expect($d.dialog('option', 'position').my).toBe('center top');
    });

    test('one not sized by dialogFit is left alone', () => {
        windowOf(1280, 800);

        const $d = $('<div>').appendTo('body').dialog({ width: 500 });

        windowOf(390, 844);
        refitDialogs();

        expect($d.dialog('option', 'width')).toBe(500);
    });
});
