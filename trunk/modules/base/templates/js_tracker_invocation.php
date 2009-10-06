// set base URL
OWA.setSetting('baseUrl', '<?php echo OWA_URL;?>');
// Create a tracker
OWALogger = new OWA.tracker();
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