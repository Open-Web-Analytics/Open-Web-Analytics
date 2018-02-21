<?php if ( isset($options) && ! $this->getValue( 'no_script_wrapper', $options ) ): ?>
<!-- Start Open Web Analytics Tracker -->
<script type="text/javascript">
//<![CDATA[
<?php endif;?>
var owa_baseUrl = '<?php $this->out( owa_coreAPI::getSetting( 'base', 'public_url' ) ); ?>';
var owa_cmds = owa_cmds || [];
<?php if (owa_coreAPI::getSetting('base', 'error_handler') === 'development'): ?>
owa_cmds.push(['setDebug', true]);
<?php endif;?>
<?php if ( isset($options) && $this->getValue('apiEndpoint', $options ) ): ?>
owa_cmds.push(['setApiEndpoint', '<?php echo $options['apiEndpoint'];?>']);
<?php endif;?>
owa_cmds.push(['setSiteId', '<?php echo $site_id; ?>']);
<?php if ( isset($options) && $this->getValue( 'cmds', $options ) ): ?>
<?php $this->out($this->getValue( 'cmds', $options ), false ); ?>
<?php endif;?>
<?php
foreach ($cmds as $cmd) {
	
	$this->out( $cmd );
	$this->out( "\n");
}
?>

(function() {
	var _owa = document.createElement('script'); _owa.type = 'text/javascript'; _owa.async = true;
	owa_baseUrl = ('https:' == document.location.protocol ? window.owa_baseSecUrl || owa_baseUrl.replace(/http:/, 'https:') : owa_baseUrl );
	_owa.src = owa_baseUrl + 'modules/base/js/owa.tracker-combined-min.js';
	var _owa_s = document.getElementsByTagName('script')[0]; _owa_s.parentNode.insertBefore(_owa, _owa_s);
}());
<?php if ( isset($options) && ! $this->getValue( 'no_script_wrapper', $options ) ): ?>
//]]>
</script>
<!-- End Open Web Analytics Code -->
<?php endif;?>