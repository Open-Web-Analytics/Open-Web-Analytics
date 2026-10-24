import { handleClick, open, close, OPEN, setColumnHidden, COLUMN_KEY } from '../../modules/Base/src/reporting/v1/owa.reportnav.js';

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
