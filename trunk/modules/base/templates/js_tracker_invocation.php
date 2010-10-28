<?php if (owa_coreAPI::getSetting('base', 'error_handler') === 'development'): ?>
owa_cmds.push(['setOption','debug', true]);
<?php endif;?>
owa_cmds.push(['setEndpoint', owa_baseUrl]);
<?php if ($this->get('logger_endpoint')): ?>
owa_cmds.push(['setLoggerEndpoint', '<?php echo $logger_endpoint;?>']);
<?php endif;?>
<?php if ($this->get('apiEndpoint')): ?>
owa_cmds.push(['setApiEndpoint', '<?php echo $apiEndpoint;?>']);
<?php endif;?>
owa_cmds.push(['setSiteId', <?php if (strpos($site_id, 'owa_') === false){ echo sprintf("'%s'", $site_id); } else { echo $site_id; } ?>]);
<?php if ($this->get('cmds')): ?>
<?php $this->out($this->get('cmds'), false); ?>
<?php endif;?>
<?php if (!$do_not_log_pageview): ?>
owa_cmds.push(['trackPageView']);
<?php endif;?>
<?php if (!$do_not_log_clicks): ?>
owa_cmds.push(['trackClicks']);
<?php endif;?>
<?php if (!$do_not_log_domstream): ?>
owa_cmds.push(['trackDomStream']);
<?php endif;?>
