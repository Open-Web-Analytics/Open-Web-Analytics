<?php
namespace OWA\Module\Base\View;

/**
 * Installer Configuration Entry View
 */

class InstallConfigEntry extends \OWA\Core\View {

    /**
     * @param  array<string, mixed> $data the view's data, View::$data: name => value
     * @return void
     */
    public function render($data) {

        //page title
        $this->t->set('page_title', \OWA\Core\CoreAPI::t( 'Configuration File Generator' ));
        // load body template. On the first render there is no submitted config
        // yet ($this->get('config') is false); default to an empty array so the
        // template's $config['db_host'] etc. don't warn on a bool offset. When a
        // failed installConfig re-renders this view, it sets config to $params.
        $config = $this->get('config');
        $config = is_array($config) ? $config : [];

        // The name a created database gets, minted once and carried through a
        // re-render, so the form never shows one name and creates another.
        if ( \OWA\Module\Base\Classes\InstallDatabase::organizationIdFrom( $config['db_create_name'] ?? '' ) === null ) {

            $config['db_create_name'] = \OWA\Module\Base\Classes\InstallDatabase::nameFor(
                \OWA\Module\Base\Classes\InstallDatabase::mintOrganizationId() );
        }

        $config['db_mode'] = ( $config['db_mode'] ?? '' ) === 'create' ? 'create' : 'existing';

        $this->body->set('config', $config);
        $this->body->set_template('install_config_entry.php');
        // prepopulate the public url based on the current url.
        $public_url = \OWA\Core\Lib::get_current_url();
        $pos = strpos($public_url, 'install.php');
        $public_url = substr($public_url, 0, $pos);
        $this->body->set('public_url', $public_url);
        $this->setJs("owa", "base/dist/owa.reporting-combined-min.js");
    }
}
