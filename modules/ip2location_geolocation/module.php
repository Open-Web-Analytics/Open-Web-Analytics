<?php

require_once OWA_BASE_DIR . '/owa_module.php';

/**
 * IP2Location Geolocation Module.
 *
 * @author      IP2Location <support@ip2location.com>
 * @copyright   Copyright &copy; 2022 IP2Location
 * @license     http://www.gnu.org/copyleft/gpl.html GPL v2.0
 *
 * @category    owa
 *
 * @version        $Revision$
 *
 * @since        owa 1.7.7
 */
class owa_ip2location_geolocationModule extends owa_module
{
	public $method;

	public function __construct()
	{
		$this->name = 'ip2location_geolocation';
		$this->display_name = 'IP2Location Geolocation';
		$this->group = 'geoip';
		$this->author = 'IP2Location';
		$this->version = '1.0';
		$this->description = 'Performs IP2Location geolocation lookups.';
		$this->config_required = false;
		$this->required_schema_version = 1;

		$mode = owa_coreAPI::getSetting('ip2location_geolocation', 'lookup_method');

		switch ($mode) {
			case 'web_service':
				$method = 'getLocationFromWebService';
				break;

			case 'bin_database':
				$method = 'getLocation';
				break;

			default:
				$method = 'getLocation';
		}

		$this->method = $method;

		owa_coreAPI::setSetting('base', 'geolocation_lookup', true);
		owa_coreAPI::setSetting('base', 'geolocation_service', 'ip2location');

		return parent::__construct();
	}

	public function registerFilters()
	{
		if (owa_coreAPI::getSetting('base', 'geolocation_service') === 'ip2location') {
			$this->registerFilter('geolocation', 'ip2location', $this->method, 0, 'classes');
		}
	}
}
