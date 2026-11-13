import { AppMode } from '../../modules/Base/src/reporting/v1/owa.appmode.js';

/**
 * OWA opened from a Home Screen: pull down to refresh, and a reload on coming
 * back after STALE_AFTER_MS. Nothing at all in a browser tab.
 */

function standaloneWindow( standalone ) {
    return {
        navigator: { standalone: standalone },
        matchMedia: () => ( { matches: false } ),
        scrollY: 0,
    };
}

let doc;
let reload;

// A document per test: listeners bound by one test's start() must not see the next one's touches.
function touch( type, y ) {
    const e = new Event( type, { cancelable: true } );
    e.touches = y === null ? [] : [ { clientY: y } ];
    doc.dispatchEvent( e );
    return e;
}

beforeEach( () => {
    doc = document.implementation.createHTMLDocument( 'app' );
    doc.body.innerHTML = '<div id="page"></div>';
    reload = jest.fn();
} );

describe( 'in a browser tab', () => {

    test( 'nothing is bound', () => {
        expect( AppMode.start( standaloneWindow( false ), doc, reload ) ).toBe( false );
        expect( doc.querySelector( '.owa_pullRefresh' ) ).toBeNull();
        expect( doc.documentElement.classList.contains( 'owa_appMode' ) ).toBe( false );
    } );

    test( 'display-mode: standalone counts as app mode outside iOS', () => {
        const win = { navigator: {}, matchMedia: ( q ) => ( { matches: q === '(display-mode: standalone)' } ), scrollY: 0 };
        expect( AppMode.isStandalone( win ) ).toBe( true );
    } );
} );

describe( 'pull to refresh', () => {

    beforeEach( () => {
        AppMode.start( standaloneWindow( true ), doc, reload, () => 0 );
    } );

    test( 'a pull past the threshold refreshes', () => {
        touch( 'touchstart', 100 );
        const move = touch( 'touchmove', 100 + 2 * AppMode.PULL_THRESHOLD + 10 );
        expect( move.defaultPrevented ).toBe( true );
        expect( doc.querySelector( '.owa_pullRefresh' ).classList.contains( 'is-ready' ) ).toBe( true );

        touch( 'touchend', null );

        expect( reload ).toHaveBeenCalledTimes( 1 );
        expect( doc.querySelector( '.owa_pullRefresh' ).classList.contains( 'is-refreshing' ) ).toBe( true );
    } );

    test( 'a short pull springs back without refreshing', () => {
        touch( 'touchstart', 100 );
        touch( 'touchmove', 130 );
        touch( 'touchend', null );

        expect( reload ).not.toHaveBeenCalled();
        expect( doc.querySelector( '.owa_pullRefresh' ).style.opacity ).toBe( '0' );
    } );

    test( 'scrolling up is left to the page', () => {
        touch( 'touchstart', 300 );
        const move = touch( 'touchmove', 100 );
        touch( 'touchend', null );

        expect( move.defaultPrevented ).toBe( false );
        expect( reload ).not.toHaveBeenCalled();
    } );
} );

describe( 'a page scrolled down', () => {

    test( 'scrolls rather than pulls', () => {
        const win = standaloneWindow( true );
        win.scrollY = 200;
        AppMode.start( win, doc, reload, () => 0 );

        touch( 'touchstart', 100 );
        const move = touch( 'touchmove', 400 );
        touch( 'touchend', null );

        expect( move.defaultPrevented ).toBe( false );
        expect( reload ).not.toHaveBeenCalled();
    } );

    test( 'a content column that scrolls on its own is the one asked', () => {
        doc.body.innerHTML = '<div class="owa_contentScroll"></div>';
        const column = doc.querySelector( '.owa_contentScroll' );
        Object.defineProperty( column, 'scrollHeight', { value: 2000 } );
        Object.defineProperty( column, 'clientHeight', { value: 600 } );
        column.scrollTop = 50;

        expect( AppMode.scrollTop( standaloneWindow( true ), doc ) ).toBe( 50 );
    } );
} );

describe( 'coming back to the app', () => {

    function visibility( state ) {
        Object.defineProperty( doc, 'visibilityState', { configurable: true, get: () => state } );
        doc.dispatchEvent( new Event( 'visibilitychange' ) );
    }

    test( 'after a while away, the page reloads', () => {
        let t = 0;
        AppMode.start( standaloneWindow( true ), doc, reload, () => t );

        visibility( 'hidden' );
        t = AppMode.STALE_AFTER_MS;
        visibility( 'visible' );

        expect( reload ).toHaveBeenCalledTimes( 1 );
    } );

    test( 'after a moment away, it does not', () => {
        let t = 0;
        AppMode.start( standaloneWindow( true ), doc, reload, () => t );

        visibility( 'hidden' );
        t = 30 * 1000;
        visibility( 'visible' );

        expect( reload ).not.toHaveBeenCalled();
    } );
} );

describe( 'opening the app again', () => {

    const BASE = 'https://owa.example.com/index.php';
    const DASHBOARD_TODAY = BASE + '?owa_action=base.report&owa_propertyId=7&owa_reportId=dashboard&owa_period=today';
    const START_PAGE = BASE + '?do=base.report&site_id=&propertyId=7&reportId=dashboard&';

    function memoryStorage() {
        const items = {};
        return {
            getItem: ( k ) => ( k in items ? items[ k ] : null ),
            setItem: ( k, v ) => { items[ k ] = String( v ); },
        };
    }

    // One phone: localStorage outlives the app; sessionStorage lasts one opening.
    let local;
    let go;

    function page( href, session ) {
        return { location: { href: href }, localStorage: local, sessionStorage: session };
    }

    beforeEach( () => {
        local = memoryStorage();
        go = jest.fn();
    } );

    test( 'a launch at the start page goes back to the last report shown', () => {
        AppMode.resume( page( DASHBOARD_TODAY, memoryStorage() ), go );

        // The phone closed the app; it opens again at start_url, which redirects here.
        expect( AppMode.resume( page( START_PAGE, memoryStorage() ), go ) ).toBe( true );
        expect( go ).toHaveBeenCalledWith( DASHBOARD_TODAY );
    } );

    test( 'within one opening, a report page without a period is not redirected', () => {
        const session = memoryStorage();
        AppMode.resume( page( DASHBOARD_TODAY, session ), go );

        expect( AppMode.resume( page( START_PAGE, session ), go ) ).toBe( false );
        expect( go ).not.toHaveBeenCalled();
        expect( local.getItem( AppMode.LAST_VIEW_KEY ) ).toBe( START_PAGE );
    } );

    test( 'a first page that names a period is kept', () => {
        AppMode.resume( page( DASHBOARD_TODAY, memoryStorage() ), go );
        const lastMonth = BASE + '?do=base.report&reportId=pages&period=last_month';

        expect( AppMode.resume( page( lastMonth, memoryStorage() ), go ) ).toBe( false );
        expect( local.getItem( AppMode.LAST_VIEW_KEY ) ).toBe( lastMonth );
    } );

    test( 'the sign-in form does not use up the launch', () => {
        AppMode.resume( page( DASHBOARD_TODAY, memoryStorage() ), go );
        const session = memoryStorage();

        expect( AppMode.resume( page( BASE + '?do=base.loginForm', session ), go ) ).toBe( false );
        expect( AppMode.resume( page( START_PAGE, session ), go ) ).toBe( true );
        expect( go ).toHaveBeenCalledWith( DASHBOARD_TODAY );
    } );

    test( 'only report pages of this install without a nonce are remembered', () => {
        const win = page( DASHBOARD_TODAY, memoryStorage() );

        expect( AppMode.isResumable( DASHBOARD_TODAY, win ) ).toBe( true );
        expect( AppMode.isResumable( BASE + '?do=base.sitesProfile&siteId=1', win ) ).toBe( false );
        expect( AppMode.isResumable( BASE + '?do=base.reportEdit&nonce=abc', win ) ).toBe( false );
        expect( AppMode.isResumable( 'https://elsewhere.example/index.php?do=base.report', win ) ).toBe( false );
    } );

    test( 'no first launch has nothing to go back to', () => {
        expect( AppMode.resume( page( START_PAGE, memoryStorage() ), go ) ).toBe( false );
        expect( go ).not.toHaveBeenCalled();
    } );

    test( 'storage that refuses access leaves the page as it is', () => {
        const win = { location: { href: START_PAGE } };
        Object.defineProperty( win, 'localStorage', { get: () => { throw new Error( 'denied' ); } } );
        win.sessionStorage = memoryStorage();

        expect( AppMode.resume( win, go ) ).toBe( false );
        expect( go ).not.toHaveBeenCalled();
    } );
} );
