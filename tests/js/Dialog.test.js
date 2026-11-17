const $ = require('jquery');

global.jQuery = global.$ = $;
window.jQuery = window.$ = $;

import { OWA } from '../../modules/Base/src/reporting/owa.format.js';
import { dialog } from '../../modules/Base/src/reporting/owa.dialog.js';
import '../../modules/Base/src/reporting/owa.confirm.js';

/**
 * A modal dialog on the browser's <dialog> (owa.dialog.js), and the
 * confirmation built on it (owa.confirm.js).
 *
 * jsdom has the element but not showModal() or close(), so they are stood in
 * for as the browser behaves: showModal() opens it, close() shuts it and the
 * close event follows in a later task. Escape in a browser is close() called
 * by the browser itself, which is how the tests below press it.
 */

beforeAll(() => {
    HTMLDialogElement.prototype.showModal = function () {
        this.setAttribute('open', '');
    };
    HTMLDialogElement.prototype.close = function () {
        if (!this.hasAttribute('open')) {
            return;
        }
        this.removeAttribute('open');
        setTimeout(() => this.dispatchEvent(new Event('close')), 0);
    };
});

afterEach(() => {
    document.body.innerHTML = '';
});

const tick = () => new Promise((resolve) => setTimeout(resolve, 0));

/** What the browser does on Escape: close the dialog, not through the helper. */
function pressEscape(el) {
    HTMLDialogElement.prototype.close.call(el);
}

function openerButton() {
    const b = document.createElement('button');
    b.textContent = 'Open';
    document.body.appendChild(b);
    b.focus();
    return b;
}

describe('a dialog', () => {

    test('is a <dialog> opened modal, named by its heading', () => {
        const content = document.createElement('p');
        content.textContent = 'Body';

        const d = dialog(content, { title: 'Delete this user?' }).open();
        const el = d.element;

        expect(el.tagName).toBe('DIALOG');
        expect(el.open).toBe(true);

        const heading = document.getElementById(el.getAttribute('aria-labelledby'));
        expect(heading.tagName).toBe('H2');
        expect(heading.textContent).toBe('Delete this user?');
        expect(el.contains(content)).toBe(true);
    });

    test('takes the place of content already on the page, which is shown inside it', () => {
        document.body.innerHTML = '<div class="owa"><div id="gallery" title="Add a widget" hidden><a href="#x">Tile</a></div></div>';
        const content = document.getElementById('gallery');

        const d = dialog(content, {});

        expect(d.element.parentNode).toBe(document.querySelector('.owa'));
        expect(content.hidden).toBe(false);
        expect(d.isOpen()).toBe(false);

        // Its title attribute is the heading, and no longer a tooltip.
        expect(d.element.querySelector('.owa_dialogTitle').textContent).toBe('Add a widget');
        expect(content.hasAttribute('title')).toBe(false);
    });

    test('content declared with an inline display:none is shown inside it', () => {
        document.body.innerHTML = '<div id="c" style="display:none;"><a href="#x">Tile</a></div>';

        dialog(document.getElementById('c'), { title: 'T' });

        expect(document.getElementById('c').style.display).toBe('');
    });

    test('content not on the page goes at the end of <body>', () => {
        const d = dialog($('<div>Hi</div>'), { title: 'T' });

        expect(d.element.parentNode).toBe(document.body);
        expect(d.body.textContent).toBe('Hi');
    });

    test('says its width on a wide screen to the stylesheet', () => {
        const d = dialog(document.createElement('div'), { title: 'T', width: 760 });

        expect(d.element.style.getPropertyValue('--owa-dialog-width')).toBe('760px');
        expect(d.element.classList.contains('owa_dialog')).toBe(true);
    });

    test('draws its buttons in order, and each is given the dialog', () => {
        const clicked = [];
        const d = dialog(document.createElement('div'), {
            title: 'T',
            buttons: [
                { text: 'Cancel', className: 'owa-button owa-button-quiet', click: (h) => clicked.push(['cancel', h]) },
                { text: 'Create', className: 'owa-button', click: (h) => clicked.push(['create', h]) },
            ],
        });

        const buttons = [...d.element.querySelectorAll('.owa_dialogButtons button')];

        expect(buttons.map((b) => b.textContent)).toEqual(['Cancel', 'Create']);
        expect(buttons.map((b) => b.type)).toEqual(['button', 'button']);
        expect(buttons[0].className).toBe('owa-button owa-button-quiet');

        buttons[1].click();

        expect(clicked).toEqual([['create', d]]);
    });

    test('changes its buttons and title while open', () => {
        const d = dialog(document.createElement('div'), {
            title: 'Add a widget',
            buttons: [{ text: 'Create', click: () => {} }],
        }).open();

        d.setButtons([{ text: 'Done', click: () => {} }]);
        d.setTitle('Change the widget type');

        expect([...d.element.querySelectorAll('.owa_dialogButtons button')].map((b) => b.textContent)).toEqual(['Done']);
        expect(d.element.querySelector('.owa_dialogTitle').textContent).toBe('Change the widget type');
    });

    test('with no buttons, has no button row', () => {
        const d = dialog(document.createElement('div'), { title: 'T' });

        expect(d.element.querySelector('.owa_dialogButtons').hidden).toBe(true);
    });

    test('focuses the first control in its content when it opens', () => {
        document.body.innerHTML = '<div id="c"><input type="hidden" name="nonce"><input name="name"></div>';

        dialog(document.getElementById('c'), {
            title: 'T',
            closeButton: true,
            buttons: [{ text: 'Create', click: () => {} }],
        }).open();

        expect(document.activeElement).toBe(document.querySelector('input[name="name"]'));
    });

    test('with no control in its content, focuses its first button', () => {
        const d = dialog($('<p>Sure?</p>'), {
            title: 'T',
            buttons: [{ text: 'Cancel', click: () => {} }, { text: 'Delete', click: () => {} }],
        }).open();

        expect(document.activeElement.textContent).toBe('Cancel');
        expect(d.element.contains(document.activeElement)).toBe(true);
    });

    test('a close button in the heading is labelled and closes it', () => {
        const opener = openerButton();
        const d = dialog($('<p>x</p>'), { title: 'T', closeButton: true }).open();

        const x = d.element.querySelector('.owa_dialogHead button');

        expect(x.getAttribute('aria-label')).toBe('Close');

        x.click();

        expect(d.isOpen()).toBe(false);
        expect(document.activeElement).toBe(opener);
    });

    test('closed by the page, focus is back on its opener before close() returns', () => {
        const opener = openerButton();
        const closed = jest.fn();
        const d = dialog($('<p>x</p>'), {
            title: 'T',
            buttons: [{ text: 'OK', click: () => {} }],
            onClose: closed,
        }).open();

        expect(document.activeElement).not.toBe(opener);

        d.close();

        expect(d.isOpen()).toBe(false);
        expect(document.activeElement).toBe(opener);
        expect(closed).toHaveBeenCalledTimes(1);
    });

    test('onClose runs once, though the browser\'s close event follows', async () => {
        const closed = jest.fn();
        const d = dialog($('<p>x</p>'), { title: 'T', onClose: closed }).open();

        d.close();
        await tick();

        expect(closed).toHaveBeenCalledTimes(1);
    });

    test('Escape closes it, focus goes back to its opener, and onClose runs', async () => {
        const opener = openerButton();
        const closed = jest.fn();
        const d = dialog($('<p>x</p>'), {
            title: 'T',
            buttons: [{ text: 'OK', click: () => {} }],
            onClose: closed,
        }).open();

        pressEscape(d.element);
        await tick();

        expect(d.isOpen()).toBe(false);
        expect(document.activeElement).toBe(opener);
        expect(closed).toHaveBeenCalledTimes(1);
    });

    test('an opener given to open() is the one focus returns to', () => {
        const named = openerButton();
        openerButton();

        const d = dialog($('<p>x</p>'), { title: 'T', buttons: [{ text: 'OK', click: () => {} }] }).open(named);
        d.close();

        expect(document.activeElement).toBe(named);
    });

    test('opens again after closing, and returns focus each time', () => {
        const opener = openerButton();
        const closed = jest.fn();
        const d = dialog($('<p>x</p>'), { title: 'T', buttons: [{ text: 'OK', click: () => {} }], onClose: closed });

        d.open();
        d.close();
        d.open(opener);

        expect(d.isOpen()).toBe(true);

        d.close();

        expect(document.activeElement).toBe(opener);
        expect(closed).toHaveBeenCalledTimes(2);
    });

    test('removeOnClose takes it out of the page', () => {
        const d = dialog($('<p>x</p>'), { title: 'T', removeOnClose: true }).open();

        d.close();

        expect(d.element.isConnected).toBe(false);
    });

    test('each dialog\'s heading id is its own', () => {
        const a = dialog($('<p>a</p>'), { title: 'A' });
        const b = dialog($('<p>b</p>'), { title: 'B' });

        expect(a.element.getAttribute('aria-labelledby')).not.toBe(b.element.getAttribute('aria-labelledby'));
    });

    test('is on the OWA namespace for the templates\' inline scripts', () => {
        expect(OWA.dialog).toBe(dialog);
    });
});

describe('the confirmation', () => {

    const frame = () => document.querySelector('dialog.owa_confirmDialogFrame');

    test('asks in a dialog named by its title, Cancel first and focused', () => {
        OWA.confirmAction({ title: 'Delete this user?', body: 'Gone.', proceed: 'Delete user' }, () => {});

        const el = frame();

        expect(el.open).toBe(true);
        expect(document.getElementById(el.getAttribute('aria-labelledby')).textContent).toBe('Delete this user?');
        expect(el.querySelector('[data-ui="dialog:confirm"]').textContent).toBe('Gone.');

        const buttons = [...el.querySelectorAll('.owa_dialogButtons button')];

        expect(buttons.map((b) => b.textContent)).toEqual(['Cancel', 'Delete user']);
        expect(buttons[1].classList.contains('owa-button-danger')).toBe(true);
        expect(document.activeElement).toBe(buttons[0]);
        expect(el.style.getPropertyValue('--owa-dialog-width')).toBe('440px');
    });

    test('Cancel does nothing and leaves no dialog behind', () => {
        const proceed = jest.fn();

        OWA.confirmAction({}, proceed);
        frame().querySelector('.owa_confirmCancel').click();

        expect(proceed).not.toHaveBeenCalled();
        expect(frame()).toBeNull();
    });

    test('proceeding closes it first, with focus back on the opener, then acts', () => {
        const opener = openerButton();
        const seen = [];

        OWA.confirmAction({ opener }, () => {
            seen.push([document.querySelector('dialog') === null, document.activeElement === opener]);
        });
        frame().querySelector('.owa_confirmProceed').click();

        expect(seen).toEqual([[true, true]]);
    });

    test('paragraphs are shown one each, as text', () => {
        OWA.confirmAction({ paragraphs: ['<b>One</b>', 'Two'] }, () => {});

        const ps = [...frame().querySelectorAll('.owa_confirmBody')].map((p) => p.textContent);

        expect(ps).toEqual(['<b>One</b>', 'Two']);
        expect(frame().querySelector('b')).toBeNull();
    });

    test('a notice proceeds with an ordinary button, not a red one', () => {
        OWA.confirmAction({ tone: 'notice' }, () => {});

        expect(frame().querySelector('.owa_confirmProceed').classList.contains('owa-button-danger')).toBe(false);
    });

    test('a link carrying data-owa-confirm asks first, and is followed on yes', async () => {
        await new Promise((resolve) => $(resolve));

        document.body.innerHTML = '<a href="#gone" data-owa-confirm data-owa-confirm-title="Delete this report?">Delete</a>';
        const link = document.querySelector('a');
        const followed = jest.fn();

        // After the delegated handler, as the browser's default action is.
        document.addEventListener('click', (e) => {
            if (e.target === link && !e.defaultPrevented) {
                followed();
            }
        });

        link.click();

        expect(followed).not.toHaveBeenCalled();
        expect(document.getElementById(frame().getAttribute('aria-labelledby')).textContent).toBe('Delete this report?');

        frame().querySelector('.owa_confirmProceed').click();

        expect(followed).toHaveBeenCalledTimes(1);
        expect(document.activeElement).toBe(link);
    });
});
