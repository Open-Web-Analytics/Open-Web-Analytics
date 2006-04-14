<?php

// wa env

define('WA_BASE_DIR', dirname(__FILE__));
//define('WA_VIEW_DIR', dirname(__FILE__).'/reports/');
//define('WA_TEMPLATE_DIR', WA_VIEW_DIR.'templates/');
define('OWA_INCLUDE_DIR', WA_BASE_DIR.'/includes/');
define('OWA_PEARLOG_DIR', WA_BASE_DIR.'/includes/Log-1.9.3');
define('OWA_REQ_PLUGINS_DIR', WA_BASE_DIR.'/event_plugins/');
define('OWA_METRICS_DIR', dirname(__FILE__).'/metrics/');
define('OWA_GRAPHS_DIR', dirname(__FILE__).'/graphs/');

?>