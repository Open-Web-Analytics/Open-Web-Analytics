/**
 * A 1.x tag: the tracker loaded by one of the two fixed paths old embeds
 * hardcode, both 301'd to public/base/dist/owa.tracker.js by .htaccess.
 *
 * Such a tag queues its own commands and never reads the Profile's bundle, so
 * the behaviour features the bundle turns on by default -- scroll depth, forms,
 * site search, errors, route changes -- never reached it. Started from a legacy
 * path, the tracker loads the Profile's features file instead
 * (public/tracker/features/<site id>.js, TrackerBundle::featuresSource()), which
 * pushes them onto the running queue. Page views, clicks and any feature a
 * module adds are not in it: the tag says those for itself.
 *
 * A script element keeps its ORIGINAL src through a redirect, so
 * document.currentScript.src names the path the tag asked for.
 */

const LEGACY_PATH = /\/modules\/base\/(js\/owa\.tracker-combined-min|dist\/owa\.tracker)\.js(\?|#|$)/i;

/** Whether the tracker was loaded by a 1.x tag's path. */
export function isLegacyTrackerSrc( src ) {

    return typeof src === 'string' && LEGACY_PATH.test( src );
}

/** Where a Profile's features file is. */
export function featuresUrl( baseUrl, siteId ) {

    return String( baseUrl ).replace( /\/?$/, '/' ) + 'public/tracker/features/'
        + encodeURIComponent( String( siteId ) ) + '.js';
}

/**
 * Load the Profile's features for a legacy tag, once.
 *
 * @param {Document} doc
 * @param {string}   baseUrl  owa_baseUrl
 * @param {string}   siteId   what the tag's setSiteId set
 * @return {boolean} whether it was requested
 */
export function loadProfileFeatures( doc, baseUrl, siteId ) {

    if ( ! doc || ! baseUrl || ! siteId || doc.querySelector( 'script[data-owa-features]' ) ) {

        return false;
    }

    const script = doc.createElement( 'script' );

    script.async = true;
    script.src = featuresUrl( baseUrl, siteId );
    script.setAttribute( 'data-owa-features', String( siteId ) );

    ( doc.head || doc.documentElement ).appendChild( script );

    return true;
}
