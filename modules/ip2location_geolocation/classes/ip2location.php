<?php

require_once OWA_BASE_DIR . '/owa_location.php';

if (!class_exists('\IP2Location\Database')) {
	require_once OWA_MODULES_DIR . 'ip2location_geolocation/includes/IP2Location/Database.php';
}

if (!class_exists('\IP2Location\WebService')) {
	require_once OWA_MODULES_DIR . 'ip2location_geolocation/includes/IP2Location/WebService.php';
}

if (!defined('OWA_IP2LOCATION_DATA_DIR')) {
	define('OWA_IP2LOCATION_DATA_DIR', OWA_DATA_DIR . 'ip2location/');
}

/**
 * IP2Location Geolocation Wrapper.
 *
 * See https://www.ip2location.com/development-libraries/ip2location/php for API documentation
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
class owa_ip2location extends owa_location
{
	/**
	 * URL template for REST based web service.
	 *
	 * @var unknown_type
	 */
	public $ws_url = '';
	public $db_file_dir;
	public $db_file_name = 'IP2LOCATION.BIN';
	public $db_file_path;
	public $db_file_present = false;

	/**
	 * Constructor.
	 *
	 * @return owa_hostip
	 */
	public function __construct()
	{
		return parent::__construct();
	}

	public function isDbReady()
	{
		$this->db_file_path = OWA_IP2LOCATION_DATA_DIR . $this->db_file_name;

		if (file_exists($this->db_file_path)) {
			$this->db_file_present = true;
		} else {
			owa_coreAPI::notice('IP2Location BIN database is not found at: ' . OWA_IP2LOCATION_DATA_DIR);
		}

		return $this->db_file_present;
	}

	/**
	 * Fetches the location from the IP2Location BIN database.
	 *
	 * @param string $ip
	 */
	public function getLocation($location_map)
	{
		if (!$this->isDbReady()) {
			return $location_map;
		}

		if (!array_key_exists('ip_address', $location_map)) {
			return $location_map;
		}

		$db = new \IP2Location\Database($this->db_file_path, \IP2Location\Database::FILE_IO);

		$record = $db->lookup(trim($location_map['ip_address']), \IP2Location\Database::ALL);

		if ($record) {
			$location_map = $this->mapCityRecord($record, $location_map);
		}

		return $location_map;
	}

	public function getLocationFromWebService($location_map)
	{
		$api_key = owa_coreAPI::getSetting('ip2location_geolocation', 'api_key');

		if (!array_key_exists('ip_address', $location_map)) {
			return $location_map;
		}

		$ws = new \IP2Location\WebService($api_key, 'WS3', true);
		$record = $ws->lookup(trim($location_map['ip_address']));

		if ($record) {
			$location_map = $this->mapCityRecord($record, $location_map);
		}

		return $location_map;
	}

	private function mapCityRecord($record, $location_map = [])
	{
		if ($record && is_array($record)) {
			if (isset($record['countryCode'])) {
				$location_map['country_code'] = $record['countryCode'];
			} elseif (isset($record['country_code'])) {
				$location_map['country_code'] = $record['country_code'];
			}

			if (isset($record['countryName'])) {
				$location_map['country'] = $record['countryName'];
			} elseif (isset($record['country_name'])) {
				$location_map['country'] = $record['country_name'];
			}

			if (isset($record['regionName'])) {
				$location_map['state'] = $record['regionName'];
			} elseif (isset($record['region_name'])) {
				$location_map['state'] = $record['region_name'];
			}

			if (isset($record['cityName'])) {
				$location_map['city'] = $record['cityName'];
			} elseif (isset($record['city_name'])) {
				$location_map['city'] = $record['city_name'];
			}

			if (isset($record['latitude'])) {
				$location_map['latitude'] = $record['latitude'];
			}

			if (isset($record['longitude'])) {
				$location_map['longitude'] = $record['longitude'];
			}

			if (isset($record['zipCode'])) {
				$location_map['postal_code'] = $record['zipCode'];
			} elseif (isset($record['zip_code'])) {
				$location_map['postal_code'] = $record['zip_code'];
			}
		}

		return $location_map;
	}
}
