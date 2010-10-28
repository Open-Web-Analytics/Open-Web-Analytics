<!-- Start Open Web Analytics Tracker -->
<script type="text/javascript">
//<![CDATA[
var owa_baseUrl = '<?php $this->out( owa_coreAPI::getSetting( 'base', 'public_url' ) ); ?>';
var owa_cmds = owa_cmds || [];
<?php include_once($this->getTemplatePath('base','js_tracker_invocation.php'));?>

(function() {
	var _owa = document.createElement('script'); _owa.type = 'text/javascript'; _owa.async = true;
	_owa.src = owa_baseUrl + 'modules/base/js/owa.tracker-combined-min.js';
	var _owa_s = document.getElementsByTagName('script')[0]; _owa_s.parentNode.insertBefore(_owa, _owa_s);
}());
//]]>
</script>
<!-- End Open Web Analytics Code -->