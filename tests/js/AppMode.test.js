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
