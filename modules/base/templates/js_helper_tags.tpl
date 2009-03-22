<!-- OWA Helper Tag Tags -->

<? if ($first_hit_tag === true):?>

<script type="text/javascript">
//<![CDATA[
document.write('<img src="<?=$this->makeAbsolutelink(array('action' => 'base.processFirstRequest', 'site_id' => $site_id), '', $this->config['action_url']);?>">');
//]]>
</script>

<? endif;?>


<script type="text/javascript">
// setup up site id etc.
//<![CDATA[
<?php include('js_owa_params.tpl'); ?>
//]]>
</script>



<? if ($click_tag === true):?>

<script type="text/javascript">
// OWA click logging event bindings
//<![CDATA[

/**
 * Logs Click
 *
 * Takes owa_param object which is defined by the logging tag.
 *
 * @param owa_params Object
 */
//Log Clicks
var owa_click = new OWA.click(owa_params);


/**
 * Helper function for setting properties on the click object
 *
 * Takes a DOM event object
 *
 * @param e Object
 */
function owa_setClick(e) {

	// hack for IE7
	e = e || window.event;

	owa_click.setProperties(e);
	return;

}

/**
 * Helper Function for calling the log method on the click object
 *
 */
function owa_logClick() {

	owa_click.log();
	return;
}

// Registers the handler for the on.click event so that click properties can be set
document.onclick = owa_setClick;

// Registers the handler for the beforenavigate event so that the click can be logged

if(window.addEventListener) {

window.addEventListener('beforeunload', owa_logClick, false);

} else if(window.attachEvent) {

window.attachEvent('beforeunload', owa_logClick);

}
//]]>
</script>

<div id="owa_click_bug"></div>
 						
<? endif;?>