import { CommandQueue as OwaCommandQueue } from './CommandQueue.js';
import { Util as OwaUtil } from '../common/Util.js';

// Pin webpack's runtime publicPath to the public/ asset tree.
//
// The tracker's async chunks (owa.vendors, and the overlays -- all
// admin-overlay-only, loaded via import()) now live in public/base/dist/, NOT
// beside this file. By default webpack derives the chunk base from
// document.currentScript.src, which is WRONG for an old embed that is 301'd from
// modules/base/dist/ to public/ (currentScript.src reports the pre-redirect URL,
// so chunks would be requested from the dead module path). owa_baseUrl is the same
// page global the tracker already reads for the log endpoint, so feed it to chunk
// resolution too: every chunk then loads from <baseUrl>public/base/dist/ regardless
// of where owa.tracker.js itself was served from. owa_assetsUrl, set by the tag or
// the bundle when OWA_ASSETS_URL serves public/ from elsewhere (a CDN), replaces
// <baseUrl>public/. Left as webpack's auto default (currentScript.src) only in the
// unsupported case where neither is set.
if ( typeof window !== 'undefined' && ( window.owa_assetsUrl || window.owa_baseUrl ) ) {
    __webpack_public_path__ = ( window.owa_assetsUrl || window.owa_baseUrl + 'public/' ) + 'base/dist/';
}


(function() {

    // The tracker is already running on this page: a second copy of the bundle
    // (two tags, or a tag and a plugin) finds the live queue in owa_cmds, and
    // every command pushed to it has been applied as it arrived.
    if ( typeof owa_cmds !== 'undefined' && owa_cmds && ! Array.isArray( owa_cmds )
         && typeof owa_cmds.process === 'function' ) {

        return;
    }

    // execute commands global owa_cmds command queue
    if ( typeof owa_cmds === 'undefined' ) {
        var q = new OwaCommandQueue();
    } else {
        if ( Array.isArray(owa_cmds) ) {
            var q = new OwaCommandQueue();
            q.loadCmds( owa_cmds );
        }
    }

    /*
     * honorPrivacySignals is read from the loaded queue, before process() runs
     * any command, so a site that turns it off does so with the same setOption
     * every other tracker option uses:
     *
     *   owa_cmds.push(['setOption', 'honorPrivacySignals', false]);
     *
     * Loading the queue sends nothing; when a signal is honoured, owa_cmds is
     * left as the page's plain array, no command runs, and the tracker's own
     * cookies are erased (Util.eraseTrackerCookies()). A browser refused only
     * for being automated keeps its cookies.
     */
    var honorPrivacySignals = q
        ? q.optionFor( 'OWATracker', 'honorPrivacySignals' ) !== false
        : true;

    if ( OwaUtil.isBrowserTrackable( honorPrivacySignals ) ) {

        window['owa_cmds'] = q;
        window['owa_cmds'].process();

        // Every tracker's default features, now the queued commands have set
        // the options they read (Tracker.startFeatures()): the default tracker
        // and any named one the commands created.
        q.startFeatures();

    } else if ( honorPrivacySignals && OwaUtil.sendsPrivacySignal() ) {

        OwaUtil.eraseTrackerCookies();

    } else if ( typeof navigator !== 'undefined' && navigator.webdriver === true
        && typeof console !== 'undefined' && console.log ) {

        // Only for the automation case, and only here.
        //
        // A privacy signal stays silent deliberately: it is a request from the
        // person, it is expected, and announcing it every time would be noise.
        // Automation is different -- it is nearly always a crawler, but when it
        // is not, it is a developer driving their own site and wondering why
        // nothing is recorded. Silence is the wrong failure for them, and this
        // turns "the tracker is broken" into one line naming the cause.
        //
        // console rather than OWA.debug: the bootstrap has not run, so there is
        // no configured debug setting to consult.
        console.log(
            'OWA: not tracking. navigator.webdriver is true, so this browser is under ' +
            'automation and is treated as a crawler. Set window.owa_track_automated_browsers ' +
            '= true before the tracker loads if these visits should be recorded.'
        );
    }
})();
