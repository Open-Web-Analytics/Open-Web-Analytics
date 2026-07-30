<?php /** @var \OWA\Core\ViewScope $view */ ?>
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
    var _owa = document.createElement('script'); _owa.type = 'text/javascript'; _owa.async = true;
    owa_baseUrl = ('https:' == document.location.protocol ? window.owa_baseSecUrl || owa_baseUrl.replace(/http:/, 'https:') : owa_baseUrl );
    _owa.src = owa_baseUrl + 'public/base/dist/owa.tracker.js';
    var _owa_s = document.getElementsByTagName('script')[0]; _owa_s.parentNode.insertBefore(_owa, _owa_s);
}());
<?php if ( isset($view->options) && ! $view->getValue( 'no_script_wrapper', $view->options ) ) { ?>
//]]>
</script>
<!-- End Open Web Analytics Code -->
<?php } ?>