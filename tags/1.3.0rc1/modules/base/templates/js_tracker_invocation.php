<?php if (owa_coreAPI::getSetting('base', 'error_handler') === 'development'): ?>
//OWA.setSetting('debug', true);
<?php endif;?>
// Set base URL
OWA.setSetting('baseUrl', '<?php echo owa_coreAPI::getSetting('base', 'public_url');?>');
<?php if ($apiEndpoint): ?>
OWA.setApiEndpoint('<?php echo $apiEndpoint;?>');
<?php endif;?>
// Create a tracker
OWATracker = new OWA.tracker(<?php if (isset($owa_params) && $owa_params == true): echo 'owa_params'; endif;?>);
<?php if ($endpoint): ?>
OWATracker.setEndpoint('<?php echo $endpoint;?>');
<?php endif;?>
OWATracker.setSiteId(<?php if (strpos($site_id, 'owa_') === false){ echo sprintf("'%s'", $site_id); } else { echo $site_id; } ?>);
<?php if (!$do_not_log_pageview): ?>
OWATracker.trackPageView();
<?php endif;?>
<?php if (!$do_not_log_clicks): ?>
OWATracker.trackClicks();
<?php endif;?>
<?php if (!$do_not_log_domstream): ?>
OWATracker.trackDomStream();
<?php endif;?>