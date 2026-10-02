<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * The tag that loads a Profile's tracking bundle (PLAN 2.24), rendered by
 * CoreAPI::getJsTrackerBundleTag().
 *
 * Everything the Profile is configured to do is in the bundle, so the tag is a
 * preconnect, the command queue a page adds its own page-level commands to,
 * and one script. Protocol-relative like the preconnect, so an https page asks
 * over https.
 *
 * NO COMMENTS IN WHAT IT EMITS beyond the two markers the classic tag has:
 * this goes on every page of every tracked site.
 */
$owa_bundle_url  = (string) $view->bundle_url;
$owa_bundle_host = parse_url( $owa_bundle_url, PHP_URL_HOST );
$owa_bundle_port = parse_url( $owa_bundle_url, PHP_URL_PORT );
$owa_bundle_src  = preg_replace( '#^https?:#', '', $owa_bundle_url );
?>
<?php if ( $owa_bundle_host ) { $owa_origin = '//' . $owa_bundle_host . ( $owa_bundle_port ? ':' . $owa_bundle_port : '' ); ?>
<link rel="preconnect" href="<?php $view->out( $owa_origin ); ?>">
<link rel="dns-prefetch" href="<?php $view->out( $owa_origin ); ?>">
<?php } ?>
<!-- Start Open Web Analytics Tracker -->
<script>var owa_cmds = owa_cmds || [];</script>
<script async src="<?php $view->out( $owa_bundle_src ); ?>"></script>
<!-- End Open Web Analytics Code -->
