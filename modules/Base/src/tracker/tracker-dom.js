import { CommandQueue as OwaCommandQueue } from './CommandQueue.js';
import { Util as OwaUtil } from '../common/Util.js';

// Pin webpack's runtime publicPath to the public/ asset tree.
//
// The tracker's async chunks (owa.vendors / owa.heatmap / owa.player -- all
// admin-overlay-only, loaded via import()) now live in public/base/dist/, NOT
// beside this file. By default webpack derives the chunk base from
// document.currentScript.src, which is WRONG for an old embed that is 301'd from
// modules/base/dist/ to public/ (currentScript.src reports the pre-redirect URL,
// so chunks would be requested from the dead module path). owa_baseUrl is the same
// page global the tracker already reads for the log endpoint, so feed it to chunk
// resolution too: every chunk then loads from <baseUrl>public/base/dist/ regardless
// of where owa.tracker.js itself was served from. Left as webpack's auto default
// (currentScript.src) only in the unsupported case where owa_baseUrl is unset.
if ( typeof window !== 'undefined' && window.owa_baseUrl ) {
    __webpack_public_path__ = window.owa_baseUrl + 'public/base/dist/';
}


(function() {

    if ( OwaUtil.isBrowserTrackable() ) {

        // execute commands global owa_cmds command queue
        if ( typeof owa_cmds === 'undefined' ) {
            var q = new OwaCommandQueue();
        } else {
            if ( OwaUtil.is_array(owa_cmds) ) {
                var q = new OwaCommandQueue();
                q.loadCmds( owa_cmds );
            }
        }

        window['owa_cmds'] = q;
        window['owa_cmds'].process();
    }
})();
