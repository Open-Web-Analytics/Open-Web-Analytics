<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * NOTHING in this file's JavaScript output should carry a comment.
 *
 * This snippet is emitted into every page of every tracked site, so a paragraph
 * of rationale here is a paragraph on someone else's page, on every request,
 * forever. Explanations belong in PHP comments like this one, which are read by
 * whoever maintains the template and never reach a browser.
 *
 * WHY THE LOADER APPENDS TO <head>, rather than inserting before the first
 * script element the way the old snippet did: that pattern dates from before
 * 'async' was universal, when document position decided fetch order. It has not
 * bought anything for years, and it assumes a script element exists to insert
 * before -- on a page where this snippet is served from an external file and
 * nothing else on the page is a script, the lookup returns undefined and the
 * next line dereferences its parentNode. That is a TypeError thrown before the
 * tracker is ever requested, on a page nobody tested. The fallback chain ends at
 * documentElement because a document without one is not a document.
 *
 * Open the connection to the tracker's origin while the parser is still working
 * on this snippet.
 *
 * Without this, DNS, TCP and TLS to a cold origin do not START until the script
 * element is inserted and the browser gets round to fetching it -- three round
 * trips that are pure latency and happen before a single byte of tracker code
 * arrives. A self-hosted tracker feels this far more than a third-party one:
 * GA's origin is already warm in most browsers from some other site, and yours
 * never is.
 *
 * Deliberately NOT crossorigin. The tracker script is an ordinary script fetch
 * and the beacon is a no-cors GET or sendBeacon, so an anonymous preconnect
 * would open a SECOND connection that nothing ever uses -- the usual way this
 * hint gets made worse than useless.
 *
 * The href is protocol-relative so it follows the page, which is what the
 * runtime scheme flip below does to owa_baseUrl. It is a hint: a wrong or
 * unused one costs a connection, never correctness.
 */
$owa_wraps_in_script = ! ( isset($view->options) && $view->getValue( 'no_script_wrapper', $view->options ) );
$owa_tracker_host = parse_url( \OWA\Core\CoreAPI::getSetting( 'base', 'public_url' ), PHP_URL_HOST );

/*
 * Only when this template is producing HTML. With no_script_wrapper the caller
 * is pasting the output INSIDE a <script> block it already opened, and a <link>
 * tag emitted there is not a hint -- it is a syntax error that takes the whole
 * block down, tracker included.
 */
if ( $owa_tracker_host && $owa_wraps_in_script ) {
    $owa_tracker_port = parse_url( \OWA\Core\CoreAPI::getSetting( 'base', 'public_url' ), PHP_URL_PORT );
    $owa_tracker_origin = '//' . $owa_tracker_host . ( $owa_tracker_port ? ':' . $owa_tracker_port : '' );
?>
<link rel="preconnect" href="<?php $view->out( $owa_tracker_origin ); ?>">
<link rel="dns-prefetch" href="<?php $view->out( $owa_tracker_origin ); ?>">
<?php } ?>
<?php if ( isset($view->options) && ! $view->getValue( 'no_script_wrapper', $view->options ) ) { ?>
<!-- Start Open Web Analytics Tracker -->
<script type="text/javascript">
//<![CDATA[
<?php } ?>
var owa_baseUrl = '<?php $view->out( \OWA\Core\CoreAPI::getSetting( 'base', 'public_url' ) ); ?>';
var owa_cmds = owa_cmds || [];
<?php if (\OWA\Core\CoreAPI::getSetting('base', 'error_handler') === 'development'){ ?>
owa_cmds.push(['setDebug', true]);
<?php }?>
<?php if ( isset($view->options) && $view->getValue('apiEndpoint', $view->options ) ) { ?>
owa_cmds.push(['setApiEndpoint', '<?php echo $view->options['apiEndpoint'];?>']);
<?php } ?>
owa_cmds.push(['setSiteId', '<?php echo $view->site_id; ?>']);
<?php 
    if ( isset($view->options) && $view->getValue( 'cmds', $view->options ) ) {
        $view->out($view->getValue( 'cmds', $view->options ), false );
        $view->out( "\n");
    }
?>
<?php
foreach ($view->cmds as $cmd) {

    $view->out( $cmd , false);
    $view->out( "\n");
}
?>

(function() {
    var _owa = document.createElement('script'); _owa.async = true;
    owa_baseUrl = ('https:' == document.location.protocol ? window.owa_baseSecUrl || owa_baseUrl.replace(/http:/, 'https:') : owa_baseUrl );
    _owa.src = owa_baseUrl + 'public/base/dist/owa.tracker.js';
    var _owa_p = document.head || document.getElementsByTagName('head')[0] || document.documentElement;
    _owa_p.appendChild(_owa);
}());
<?php if ( isset($view->options) && ! $view->getValue( 'no_script_wrapper', $view->options ) ) { ?>
//]]>
</script>
<!-- End Open Web Analytics Code -->
<?php } ?>