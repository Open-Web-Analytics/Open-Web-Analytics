import { handleClick, open, close, OPEN } from '../../modules/Base/src/reporting/v1/owa.reportnav.js';

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
