<?php /** @var \OWA\Core\ViewScope $view */ ?>
<?php
/*
 * The page's settings for the reporting bundle, as JSON: owa.format.js reads
 * them into OWA.config when it loads (OWA.readPageConfig()). Data, not a
 * script, so the bundle can load deferred.
 */
$owa_config = [
    'main_url'             => (string) \OWA\Core\CoreAPI::getSetting( 'base', 'main_url' ),
    'baseUrl'              => (string) \OWA\Core\CoreAPI::getSetting( 'base', 'public_url' ),
    'images_url'           => (string) \OWA\Core\CoreAPI::configSingleton()->assetsUrl(),
    // Read by OWA.getApiEndpoint().
    'rest_api_endpoint'    => (string) \OWA\Core\CoreAPI::getSetting( 'base', 'rest_api_url' ),
    'link_template'        => (string) \OWA\Core\CoreAPI::getSetting( 'base', 'link_template' ),
    // The operators a constraints string is parsed with, in the server's order (OWA.dataGrid.operators()).
    'constraint_operators' => array_keys( \OWA\Module\Base\Classes\ResultSetManager::CONSTRAINT_OPERATORS ),
    // The most rows one report request returns (OWA.dataGrid.maxRows()).
    'max_results_per_page' => (int) \OWA\Module\Base\Controller\ReportsRest::MAX_RESULTS_PER_PAGE,
    // The install's locale as a language tag (OWA.locale()): dates and numbers are written in it.
    'locale'               => \OWA\Core\Strings::htmlLang(),
];

$owa_l10n = \OWA\Core\Strings::forScript();

if ( $owa_l10n ) {

    // The reporting strings in the locale in use, and its plural rule (OWA.l(), OWA.ln()). None in English.
    $owa_config['strings']     = (object) $owa_l10n['strings'];
    $owa_config['plural_rule'] = $owa_l10n['plural'];
}

if ( \OWA\Core\Lib::inDebug() ) {

    $owa_config['debug'] = true;
}
?>
<script type="application/json" data-ui="owa:config"><?php echo \OWA\Core\Template::scriptJson( $owa_config, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ); ?></script>
