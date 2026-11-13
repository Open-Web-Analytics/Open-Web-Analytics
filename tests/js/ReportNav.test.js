import { handleClick, open, close, OPEN, setColumnHidden, COLUMN_KEY, dismissFlyout, restoreFlyout, DISMISSED } from '../../modules/Base/src/reporting/v1/owa.reportnav.js';

/**
 * The report nav behind a menu button on a narrow screen (owa.reportnav.js):
 * what each click does. Whether the narrow layout is in force is the media
 * query's question, asked by the handler before this; here it is.
 */

function render() {
    document.body.innerHTML = `
        <div class="owa_reportNavRail" id="owa_reportNavRail">
            <button class="owa_reportNavClose"></button>
            <div class="owa_siteControl"><span class="summary">Profile</span></div>
            <a href="?do=base.report&reportId=pages" class="report">Pages</a>
        </div>
        <button class="owa_reportNavToggle" aria-controls="owa_reportNavRail" aria-expanded="false"><i></i></button>
        <div class="report-body"><a href="?sort=x" class="under">a link under the panel</a></div>`;

    return {
        rail: document.getElementById('owa_reportNavRail'),
        toggle: document.querySelector('.owa_reportNavToggle'),
        isOpen: () => document.getElementById('owa_reportNavRail').classList.contains(OPEN),
    };
}

test('the menu button opens the nav and closes it again', () => {
    const el = render();

    expect(handleClick(el.toggle.querySelector('i'), el.rail)).toBe(true);
    expect(el.isOpen()).toBe(true);
    expect(el.toggle.getAttribute('aria-expanded')).toBe('true');

    expect(handleClick(el.toggle, el.rail)).toBe(true);
    expect(el.isOpen()).toBe(false);
    expect(el.toggle.getAttribute('aria-expanded')).toBe('false');
});

test('choosing a report closes the nav and lets the link go', () => {
    const el = render();
    open(el.rail);

    expect(handleClick(el.rail.querySelector('a.report'), el.rail)).toBe(false);
    expect(el.isOpen()).toBe(false);
});

test('a tap beside the open nav closes it and goes no further', () => {
    const el = render();
    open(el.rail);

    expect(handleClick(document.querySelector('a.under'), el.rail)).toBe(true);
    expect(el.isOpen()).toBe(false);
});

test('the close button inside the panel closes it', () => {
    const el = render();
    open(el.rail);

    expect(handleClick(el.rail.querySelector('.owa_reportNavClose'), el.rail)).toBe(true);
    expect(el.isOpen()).toBe(false);
});

test('a tap inside the open nav that is not a link leaves it open', () => {
    const el = render();
    open(el.rail);

    expect(handleClick(el.rail.querySelector('.summary'), el.rail)).toBe(false);
    expect(el.isOpen()).toBe(true);
});

test('while closed, a click on the report is not the nav\'s business', () => {
    const el = render();
    close(el.rail);

    expect(handleClick(document.querySelector('a.under'), el.rail)).toBe(false);
    expect(el.isOpen()).toBe(false);
});

test('the menu button is labelled with the page\'s own words, open and closed', () => {
    document.body.innerHTML = `
        <div class="owa_reportNavRail" id="owa_reportNavRail">
            <button class="owa_reportNavClose" aria-label="Hide the menu"></button>
        </div>
        <button class="owa_reportNavToggle" aria-controls="owa_reportNavRail" aria-expanded="false"
                aria-label="Show the menu"><i></i></button>`;

    const rail = document.getElementById('owa_reportNavRail');
    const toggle = document.querySelector('.owa_reportNavToggle');

    open(rail);
    expect(toggle.getAttribute('aria-label')).toBe('Hide the menu');

    close(rail);
    expect(toggle.getAttribute('aria-label')).toBe('Show the menu');

    open(rail);
    close(rail);
    expect(toggle.getAttribute('aria-label')).toBe('Show the menu');
});

function renderShell() {
    document.body.innerHTML = `
        <div class="owa_navShell has-column" id="owa_navShell">
            <nav class="owa_rail"></nav>
            <nav class="owa_navColumn">
                <button type="button" class="owa_navColumnToggle" aria-expanded="true" aria-label="Hide this column"></button>
            </nav>
        </div>`;

    return {
        shell: document.getElementById('owa_navShell'),
        toggle: document.querySelector('.owa_navColumnToggle'),
    };
}

test('the chevron hides the column, says so, and the choice is remembered', () => {
    window.localStorage.removeItem(COLUMN_KEY);
    const el = renderShell();

    setColumnHidden(el.shell, true);

    expect(el.shell.classList.contains('is-collapsed')).toBe(true);
    expect(el.toggle.getAttribute('aria-expanded')).toBe('false');
    expect(el.toggle.getAttribute('aria-label')).toBe('Show this column');
    expect(window.localStorage.getItem(COLUMN_KEY)).toBe('1');
});

test('showing the column again forgets the choice', () => {
    window.localStorage.setItem(COLUMN_KEY, '1');
    const el = renderShell();
    el.shell.classList.add('is-collapsed');

    setColumnHidden(el.shell, false);

    expect(el.shell.classList.contains('is-collapsed')).toBe(false);
    expect(el.toggle.getAttribute('aria-expanded')).toBe('true');
    expect(el.toggle.getAttribute('aria-label')).toBe('Hide this column');
    expect(window.localStorage.getItem(COLUMN_KEY)).toBeNull();
});

test('a column still hides when storage is refused', () => {
    const el = renderShell();
    const setItem = jest.spyOn(Storage.prototype, 'setItem').mockImplementation(() => {
        throw new Error('denied');
    });

    expect(() => setColumnHidden(el.shell, true)).not.toThrow();
    expect(el.shell.classList.contains('is-collapsed')).toBe(true);

    setItem.mockRestore();
});

describe('the open nav holds the focus', () => {

    function renderPage() {
        document.body.innerHTML = `
            <div class="owa">
                <a class="owa_skipLink" href="#owa_main">Skip to content</a>
                <div id="owa_header">
                    <button class="owa_reportNavToggle" aria-controls="owa_reportNavRail" aria-expanded="false"
                            aria-label="Show the menu"><i></i></button>
                </div>
                <table><tbody><tr>
                    <td class="owa_reportLeftNavColumn">
                        <div class="owa_reportNavRail" id="owa_reportNavRail">
                            <button class="owa_reportNavClose" aria-label="Hide the menu"></button>
                            <a href="?do=base.report&reportId=pages" class="report">Pages</a>
                        </div>
                    </td>
                    <td class="owa_reportContentColumn"><main id="owa_main"><a href="?sort=x" class="under">a link</a></main></td>
                </tr></tbody></table>
                <div class="already" inert></div>
            </div>`;

        return {
            rail: document.getElementById('owa_reportNavRail'),
            toggle: document.querySelector('.owa_reportNavToggle'),
            content: document.querySelector('.owa_reportContentColumn'),
        };
    }

    test('opening moves the focus into the nav and makes the page beside it inert', () => {
        const el = renderPage();
        el.toggle.focus();

        handleClick(el.toggle, el.rail);

        expect(document.activeElement).toBe(el.rail.querySelector('.owa_reportNavClose'));
        expect(el.content.hasAttribute('inert')).toBe(true);
        expect(document.getElementById('owa_header').hasAttribute('inert')).toBe(true);
        expect(document.querySelector('.owa_skipLink').hasAttribute('inert')).toBe(true);

        // Nothing the nav sits in is inert.
        for (let node = el.rail; node !== document.body; node = node.parentElement) {
            expect(node.hasAttribute('inert')).toBe(false);
        }
    });

    test('closing gives the focus back to the menu button and the page back to the keyboard', () => {
        const el = renderPage();
        open(el.rail);

        handleClick(el.rail.querySelector('.owa_reportNavClose'), el.rail);

        expect(document.activeElement).toBe(el.toggle);
        expect(document.querySelectorAll('[inert]:not(.already)')).toHaveLength(0);
        // What was inert before the nav opened stays so.
        expect(document.querySelector('.already').hasAttribute('inert')).toBe(true);
    });

    test('a nav closed with the focus elsewhere leaves it there', () => {
        const el = renderPage();
        open(el.rail);
        document.querySelector('.report').blur();

        const outside = document.querySelector('a.under');
        el.content.removeAttribute('inert');
        outside.focus();

        close(el.rail);

        expect(document.activeElement).toBe(outside);
    });

    test('closing a nav that is not open moves no focus', () => {
        const el = renderPage();
        const outside = document.querySelector('a.under');
        outside.focus();

        close(el.rail);

        expect(document.activeElement).toBe(outside);
        expect(el.content.hasAttribute('inert')).toBe(false);
    });
});

/**
 * A section's flyout in the rail shows on hover and on focus; Escape hides
 * it until the pointer and the focus have left its entry (WCAG 1.4.13).
 */
describe('the rail flyouts', () => {

    function renderShell() {
        document.body.innerHTML = `
            <div class="owa_navShell" id="owa_navShell"><nav class="owa_rail"><ul class="owa_railList">
                <li class="owa_railEntry" id="e1"><a class="owa_railItem" href="?s=1">One</a>
                    <div class="owa_railFlyout"><ul><li><a class="owa_navColumnItem" href="?r=1">Report</a></li></ul></div></li>
                <li class="owa_railEntry" id="e2"><a class="owa_railItem is-current" href="?s=2">Two</a></li>
            </ul></nav></div>
            <a href="?x" class="elsewhere">elsewhere</a>`;

        return {
            shell: document.getElementById('owa_navShell'),
            entry: document.getElementById('e1'),
        };
    }

    test('Escape with the focus in a flyout hides it and puts the focus on its section', () => {
        const el = renderShell();
        el.entry.querySelector('.owa_navColumnItem').focus();

        expect(dismissFlyout(el.shell)).toBe(true);
        expect(el.entry.classList.contains(DISMISSED)).toBe(true);
        expect(document.activeElement).toBe(el.entry.querySelector('.owa_railItem'));
    });

    test('Escape with the focus on a section hides its flyout and leaves the focus there', () => {
        const el = renderShell();
        const item = el.entry.querySelector('.owa_railItem');
        item.focus();

        expect(dismissFlyout(el.shell)).toBe(true);
        expect(document.activeElement).toBe(item);
    });

    test('Escape with no flyout showing does nothing', () => {
        const el = renderShell();

        document.querySelector('.elsewhere').focus();
        expect(dismissFlyout(el.shell)).toBe(false);

        // The current section has no flyout.
        document.querySelector('#e2 a').focus();
        expect(dismissFlyout(el.shell)).toBe(false);

        expect(el.shell.querySelectorAll('.' + DISMISSED)).toHaveLength(0);
    });

    test('a dismissed flyout shows again once the focus leaves its entry, not while it stays', () => {
        const el = renderShell();
        el.entry.classList.add(DISMISSED);

        restoreFlyout(el.entry, el.entry.querySelector('.owa_railItem'));
        expect(el.entry.classList.contains(DISMISSED)).toBe(true);

        restoreFlyout(el.entry, document.querySelector('.elsewhere'));
        expect(el.entry.classList.contains(DISMISSED)).toBe(false);
    });

    test('the dismissed state outranks hover and focus in the stylesheet', () => {
        const css = require('fs').readFileSync(require('path').join(__dirname, '../../modules/Base/css/owa.shell.css'), 'utf8');

        expect(css).toMatch(/\.owa_railEntry\.is-dismissed:hover > \.owa_railFlyout,\s*\.owa_railEntry\.is-dismissed:focus-within > \.owa_railFlyout \{\s*display: none;/);
    });
});
