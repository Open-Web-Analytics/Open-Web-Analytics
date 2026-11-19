
// OWA CONFIG SETTINGS

OWA.config.main_url = "<?php echo \OWA\Core\CoreAPI::getSetting('base', 'main_url');?>";
OWA.config.baseUrl = "<?php echo \OWA\Core\CoreAPI::getSetting('base', 'public_url');?>";
OWA.config.images_url = "<?php echo \OWA\Core\CoreAPI::configSingleton()->assetsUrl();?>";
// Read by OWA.getApiEndpoint().
OWA.config.rest_api_endpoint = "<?php echo \OWA\Core\CoreAPI::getSetting('base', 'rest_api_url');?>";
// 'ns' names cookies -- it must stay the wire namespace. OWA's own links carry no prefix.
OWA.config.ns = "<?php echo \OWA\Core\CoreAPI::getSetting('base', 'ns');?>";
OWA.config.link_template = "<?php echo \OWA\Core\CoreAPI::getSetting('base', 'link_template');?>";
// The operators a constraints string is parsed with, in the server's order (OWA.dataGrid.operators()).
OWA.config.constraint_operators = <?php echo \OWA\Core\Template::scriptJson( array_keys( \OWA\Module\Base\Classes\ResultSetManager::CONSTRAINT_OPERATORS ) ); ?>;
// The most rows one report request returns (OWA.dataGrid.maxRows()).
OWA.config.max_results_per_page = <?php echo (int) \OWA\Module\Base\Controller\ReportsRest::MAX_RESULTS_PER_PAGE; ?>;
// The install's locale as a language tag (OWA.locale()): dates and numbers are written in it.
OWA.config.locale = <?php echo \OWA\Core\Template::scriptJson( \OWA\Core\Strings::htmlLang() ); ?>;
<?php $owa_l10n = \OWA\Core\Strings::forScript(); if ( $owa_l10n ) { ?>
// The reporting strings in the locale in use, and its plural rule (OWA.l(), OWA.ln()). None in English.
OWA.config.strings = <?php echo \OWA\Core\Template::scriptJson( (object) $owa_l10n['strings'], JSON_INVALID_UTF8_SUBSTITUTE ); ?>;
OWA.config.plural_rule = <?php echo \OWA\Core\Template::scriptJson( $owa_l10n['plural'] ); ?>;
<?php } ?>
<?php if ( \OWA\Core\Lib::inDebug() ) { ?>
OWA.config.debug = true;
<?php } ?>
