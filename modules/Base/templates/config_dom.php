
// OWA CONFIG SETTINGS

OWA.config.main_url = "<?php echo \OWA\Core\CoreAPI::getSetting('base', 'main_url');?>";
OWA.config.public_url = "<?php echo \OWA\Core\CoreAPI::getSetting('base', 'public_url');?>";
OWA.config.baseUrl = "<?php echo \OWA\Core\CoreAPI::getSetting('base', 'public_url');?>";
//OWA.config.js_url = "<?php echo \OWA\Core\CoreAPI::getSetting('base', 'public_url').'js/';?>";
//OWA.config.action_url = "<?php echo \OWA\Core\CoreAPI::getSetting('base', 'action_url');?>";
OWA.config.images_url = "<?php echo \OWA\Core\CoreAPI::configSingleton()->assetsUrl();?>";
OWA.config.log_url = "<?php echo \OWA\Core\CoreAPI::configSingleton()->trackingUrl() . 'log.php';?>";
OWA.config.modules_url = "<?php echo \OWA\Core\CoreAPI::getSetting('base', 'modules_url');?>";
OWA.config.api_endpoint = "<?php echo \OWA\Core\CoreAPI::getSetting('base', 'rest_api_url');?>";
// 'ns' names cookies -- it must stay the wire namespace. OWA's own links carry no prefix.
OWA.config.ns = "<?php echo \OWA\Core\CoreAPI::getSetting('base', 'ns');?>";
OWA.config.link_template = "<?php echo \OWA\Core\CoreAPI::getSetting('base', 'link_template');?>";
// The operators a constraints string is parsed with, in the server's order (OWA.dataGrid.operators()).
OWA.config.constraint_operators = <?php echo json_encode( array_keys( \OWA\Module\Base\Classes\ResultSetManager::CONSTRAINT_OPERATORS ) ); ?>;
<?php $owa_l10n = \OWA\Core\Strings::forScript(); if ( $owa_l10n ) { ?>
// The reporting strings in the locale in use, and its plural rule (OWA.l(), OWA.ln()). None in English.
OWA.config.strings = <?php echo json_encode( (object) $owa_l10n['strings'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE ); ?>;
OWA.config.plural_rule = <?php echo json_encode( $owa_l10n['plural'] ); ?>;
<?php } ?>
<?php if ( \OWA\Core\Lib::inDebug() ) { ?>
OWA.config.debug = true;
<?php } ?>
