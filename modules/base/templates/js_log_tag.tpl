<!-- Start Open Web Analytics Tracker -->
<script type="text/javascript" src="<?php echo OWA_MODULES_URL.'base/js/owa.tracker-combined-min.js');?>"></script>
<script type="text/javascript">
//<![CDATA[
// set base URL
OWA.config.baseUrl = <?php echo OWA_URL;?>;
// Create a tracker
OWALogger = new OWA.logger();
OWALogger.setSiteId(<?php echo $site_id;?>);
OWALogger.setEndpoint(OWA.config.baseUrl + 'log.php');
<?php //if ($log_pageview === true): ?>
OWALogger.trackPageView();
<?php //endif;?>
<?php if ($log_clicks === true): ?>
OWALogger.trackClicks();
<?php endif;?>
<?php if (owa_coreAPI::getSetting('base', 'log_dom_stream') === true): ?>
OWALogger.trackDomStream();
<?php endif;?>
//]]>
</script>
<!-- End Open Web Analytics Code -->