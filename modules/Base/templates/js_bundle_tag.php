<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * The tag that loads a Profile's tracking bundle (PLAN 2.24), rendered by
 * CoreAPI::getJsTrackerBundleTag().
 *
 * Everything the Profile is configured to do is in the bundle, so the tag is a
 * preconnect, the command queue a page adds its own page-level commands to,
 * and one script.
 *
 * The URL keeps the scheme OWA is configured with (public_url), as does the
 * preconnect. Not protocol-relative: an https tracker loads on an http page
 * too, and an http one is blocked on an https page whichever way it is
 * written, so leaving the scheme to the page only ever helped a wrong
 * public_url -- and made the tag look broken to anyone reading it.
 *
 * NO COMMENTS IN WHAT IT EMITS beyond the two markers the classic tag has:
 * this goes on every page of every tracked site.
 */
$owa_bundle_url = (string) $view->bundle_url;
$owa_origins    = array();

// The bundle's origin (the assets URL), and the beacons' (the tracking URL) when it is another.
foreach ( array( $owa_bundle_url, \OWA\Module\Base\Classes\TrackerBundle::trackingUrl() ) as $owa_url ) {

    $owa_scheme = parse_url( $owa_url, PHP_URL_SCHEME );
    $owa_host   = parse_url( $owa_url, PHP_URL_HOST );
    $owa_port   = parse_url( $owa_url, PHP_URL_PORT );

    if ( $owa_scheme && $owa_host ) {

        $owa_origins[ $owa_scheme . '://' . $owa_host . ( $owa_port ? ':' . $owa_port : '' ) ] = true;
    }
}
?>
<?php foreach ( array_keys( $owa_origins ) as $owa_origin ) { ?>
<link rel="preconnect" href="<?php $view->out( $owa_origin ); ?>">
<link rel="dns-prefetch" href="<?php $view->out( $owa_origin ); ?>">
<?php } ?>
<!-- Start Open Web Analytics Tracker -->
<script>var owa_cmds = owa_cmds || [];</script>
<script async src="<?php $view->out( $owa_bundle_url ); ?>"></script>
<!-- End Open Web Analytics Code -->
