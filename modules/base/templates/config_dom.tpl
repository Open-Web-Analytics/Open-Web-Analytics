
// OWA CONFIG SETTINGS

OWA.config.main_url = "<?php echo owa_coreAPI::getSetting('base', 'main_url');?>";
OWA.config.public_url = "<?php echo owa_coreAPI::getSetting('base', 'public_url');?>";
OWA.config.baseUrl = "<?php echo owa_coreAPI::getSetting('base', 'public_url');?>";
//OWA.config.js_url = "<?php echo owa_coreAPI::getSetting('base', 'public_url').'js/';?>";
//OWA.config.action_url = "<?php echo owa_coreAPI::getSetting('base', 'action_url');?>";
OWA.config.images_url = "<?php echo owa_coreAPI::getSetting('base', 'images_url');?>";
OWA.config.log_url = "<?php echo owa_coreAPI::getSetting('base', 'log_url');?>";
OWA.config.modules_url = "<?php echo owa_coreAPI::getSetting('base', 'modules_url');?>";
OWA.config.api_endpoint = "<?php echo owa_coreAPI::getSetting('base', 'rest_api_url');?>";
OWA.config.ns = "<?php echo owa_coreAPI::getSetting('base', 'ns');?>";
OWA.config.link_template = "<?php echo owa_coreAPI::getSetting('base', 'link_template');?>";
<?php if (defined('OWA_ERROR_HANDLER') && OWA_ERROR_HANDLER === 'development') { ?>
OWA.config.debug = true;
<?php } ?>
