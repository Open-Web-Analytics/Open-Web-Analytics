<?php

namespace IP2Location;

/**
 * IP2Location database class.
 */
class Database
{
	/**
	 * Current module's version.
	 *
	 * @var string
	 */
	public const VERSION = '9.5.3';

	/**
	 * Unsupported field message.
	 *
	 * @var string
	 */
	public const FIELD_NOT_SUPPORTED = 'This parameter is unavailable in selected .BIN data file. Please upgrade data file.';

	/**
	 * Unknown field message.
	 *
	 * @var string
	 */
	public const FIELD_NOT_KNOWN = 'This parameter is inexistent. Please verify.';

	/**
	 * Invalid IP address message.
	 *
	 * @var string
	 */
	public const INVALID_IP_ADDRESS = 'Invalid IP address.';

	/**
	 * Maximum IPv4 number.
	 *
	 * @var int
	 */
	public const MAX_IPV4_RANGE = 4294967295;

	/**
	 * MAximum IPv6 number.
	 *
	 * @var int
	 */
	public const MAX_IPV6_RANGE = 340282366920938463463374607431768211455;

	/**
	 * Country code (ISO 3166-1 Alpha 2).
	 *
	 * @var int
	 */
	public const COUNTRY_CODE = 1;

	/**
	 * Country name.
	 *
	 * @var int
	 */
	public const COUNTRY_NAME = 2;

	/**
	 * Region name.
	 *
	 * @var int
	 */
	public const REGION_NAME = 3;

	/**
	 * City name.
	 *
	 * @var int
	 */
	public const CITY_NAME = 4;

	/**
	 * Latitude.
	 *
	 * @var int
	 */
	public const LATITUDE = 5;

	/**
	 * Longitude.
	 *
	 * @var int
	 */
	public const LONGITUDE = 6;

	/**
	 * ISP name.
	 *
	 * @var int
	 */
	public const ISP = 7;

	/**
	 * Domain name.
	 *
	 * @var int
	 */
	public const DOMAIN_NAME = 8;

	/**
	 * Zip code.
	 *
	 * @var int
	 */
	public const ZIP_CODE = 9;

	/**
	 * Time zone.
	 *
	 * @var int
	 */
	public const TIME_ZONE = 10;

	/**
	 * Net speed.
	 *
	 * @var int
	 */
	public const NET_SPEED = 11;

	/**
	 * IDD code.
	 *
	 * @var int
	 */
	public const IDD_CODE = 12;

	/**
	 * Area code.
	 *
	 * @var int
	 */
	public const AREA_CODE = 13;

	/**
	 * Weather station code.
	 *
	 * @var int
	 */
	public const WEATHER_STATION_CODE = 14;

	/**
	 * Weather station name.
	 *
	 * @var int
	 */
	public const WEATHER_STATION_NAME = 15;

	/**
	 * Mobile Country Code.
	 *
	 * @var int
	 */
	public const MCC = 16;

	/**
	 * Mobile Network Code.
	 *
	 * @var int
	 */
	public const MNC = 17;

	/**
	 * Mobile carrier name.
	 *
	 * @var int
	 */
	public const MOBILE_CARRIER_NAME = 18;

	/**
	 * Elevation.
	 *
	 * @var int
	 */
	public const ELEVATION = 19;

	/**
	 * Usage type.
	 *
	 * @var int
	 */
	public const USAGE_TYPE = 20;

	/**
	 * Address type.
	 *
	 * @var int
	 */
	public const ADDRESS_TYPE = 21;

	/**
	 * Category.
	 *
	 * @var int
	 */
	public const CATEGORY = 22;

	/**
	 * Country name and code.
	 *
	 * @var int
	 */
	public const COUNTRY = 101;

	/**
	 * Latitude and Longitude.
	 *
	 * @var int
	 */
	public const COORDINATES = 102;

	/**
	 * IDD and area codes.
	 *
	 * @var int
	 */
	public const IDD_AREA = 103;

	/**
	 * Weather station name and code.
	 *
	 * @var int
	 */
	public const WEATHER_STATION = 104;

	/**
	 * MCC, MNC, and mobile carrier name.
	 *
	 * @var int
	 */
	public const MCC_MNC_MOBILE_CARRIER_NAME = 105;

	/**
	 * All fields at once.
	 *
	 * @var int
	 */
	public const ALL = 1001;

	/**
	 * Include the IP address of the looked up IP address.
	 *
	 * @var int
	 */
	public const IP_ADDRESS = 1002;

	/**
	 * Include the IP version of the looked up IP address.
	 *
	 * @var int
	 */
	public const IP_VERSION = 1003;

	/**
	 * Include the IP number of the looked up IP address.
	 *
	 * @var int
	 */
	public const IP_NUMBER = 1004;

	/**
	 * Generic exception code.
	 *
	 * @var int
	 */
	public const EXCEPTION = 10000;

	/**
	 * No shmop extension found.
	 *
	 * @var int
	 */
	public const EXCEPTION_NO_SHMOP = 10001;

	/**
	 * Failed to open shmop memory segment for reading.
	 *
	 * @var int
	 */
	public const EXCEPTION_SHMOP_READING_FAILED = 10002;

	/**
	 * Failed to open shmop memory segment for writing.
	 *
	 * @var int
	 */
	public const EXCEPTION_SHMOP_WRITING_FAILED = 10003;

	/**
	 * Failed to create shmop memory segment.
	 *
	 * @var int
	 */
	public const EXCEPTION_SHMOP_CREATE_FAILED = 10004;

	/**
	 * The specified database file was not found.
	 *
	 * @var int
	 */
	public const EXCEPTION_DATABASE_FILE_NOT_FOUND = 10005;

	/**
	 * Not enough memory to load database file.
	 *
	 * @var int
	 */
	public const EXCEPTION_NO_MEMORY = 10006;

	/**
	 * No candidate database files found.
	 *
	 * @var int
	 */
	public const EXCEPTION_NO_CANDIDATES = 10007;

	/**
	 * Failed to open database file.
	 *
	 * @var int
	 */
	public const EXCEPTION_FILE_OPEN_FAILED = 10008;

	/**
	 * Failed to determine the current path.
	 *
	 * @var int
	 */
	public const EXCEPTION_NO_PATH = 10009;

	/**
	 * Invalid BIN database file.
	 *
	 * @var int
	 */
	public const EXCEPTION_INVALID_BIN_DATABASE = 10010;

	/**
	 * Failed to delete shmop memory segment.
	 *
	 * @var int
	 */
	public const EXCEPTION_SHMOP_DELETE_FAILED = 10011;

	/**
	 * Directly read from the database file.
	 *
	 * @var int
	 */
	public const FILE_IO = 100001;

	/**
	 * Read the whole database into a variable for caching.
	 *
	 * @var int
	 */
	public const MEMORY_CACHE = 100002;

	/**
	 * Use shared memory objects for caching.
	 *
	 * @var int
	 */
	public const SHARED_MEMORY = 100003;

	/**
	 * Share memory segment's permissions (for creation).
	 *
	 * @var int
	 */
	public const SHM_PERMS = 0600;

	/**
	 * Number of bytes to read/write at a time in order to load the shared memory cache (512k).
	 *
	 * @var int
	 */
	public const SHM_CHUNK_SIZE = 524288;

	/**
	 * Column offset mapping.
	 *
	 * Each entry contains an array mapping database version (0--23) to offset within a record.
	 * A value of 0 means the column is not present in the given database version.
	 *
	 * @var array
	 */
	private $columns = [
		self::COUNTRY_CODE         => [8,  8,  8,  8,  8,  8,  8,  8,  8,  8,  8,  8,  8,  8,  8,  8,  8,  8,  8,  8,  8,  8,  8,  8,  8],
		self::COUNTRY_NAME         => [8,  8,  8,  8,  8,  8,  8,  8,  8,  8,  8,  8,  8,  8,  8,  8,  8,  8,  8,  8,  8,  8,  8,  8,  8],
		self::REGION_NAME          => [0,  0, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12, 12],
		self::CITY_NAME            => [0,  0, 16, 16, 16, 16, 16, 16, 16, 16, 16, 16, 16, 16, 16, 16, 16, 16, 16, 16, 16, 16, 16, 16, 16],
		self::LATITUDE             => [0,  0,  0,  0, 20, 20,  0, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20, 20],
		self::LONGITUDE            => [0,  0,  0,  0, 24, 24,  0, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24, 24],
		self::ZIP_CODE             => [0,  0,  0,  0,  0,  0,  0,  0, 28, 28, 28, 28,  0, 28, 28, 28,  0, 28,  0, 28, 28, 28,  0, 28, 28],
		self::TIME_ZONE            => [0,  0,  0,  0,  0,  0,  0,  0,  0,  0, 32, 32, 28, 32, 32, 32, 28, 32,  0, 32, 32, 32,  0, 32, 32],
		self::ISP                  => [0, 12,  0, 20,  0, 28, 20, 28,  0, 32,  0, 36,  0, 36,  0, 36,  0, 36, 28, 36,  0, 36, 28, 36, 36],
		self::DOMAIN_NAME          => [0,  0,  0,  0,  0,  0, 24, 32,  0, 36,  0, 40,  0, 40,  0, 40,  0, 40, 32, 40,  0, 40, 32, 40, 40],
		self::NET_SPEED            => [0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0, 32, 44,  0, 44, 32, 44,  0, 44,  0, 44,  0, 44, 44],
		self::IDD_CODE             => [0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0, 36, 48,  0, 48,  0, 48, 36, 48,  0, 48, 48],
		self::AREA_CODE            => [0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0, 40, 52,  0, 52,  0, 52, 40, 52,  0, 52, 52],
		self::WEATHER_STATION_CODE => [0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0, 36, 56,  0, 56,  0, 56,  0, 56, 56],
		self::WEATHER_STATION_NAME => [0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0, 40, 60,  0, 60,  0, 60,  0, 60, 60],
		self::MCC                  => [0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0, 36, 64,  0, 64, 36, 64, 64],
		self::MNC                  => [0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0, 40, 68,  0, 68, 40, 68, 68],
		self::MOBILE_CARRIER_NAME  => [0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0, 44, 72,  0, 72, 44, 72, 72],
		self::ELEVATION            => [0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0, 44, 76,  0, 76, 76],
		self::USAGE_TYPE           => [0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0, 48, 80, 80],
		self::ADDRESS_TYPE         => [0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0, 84],
		self::CATEGORY             => [0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0,  0, 88],
	];

	/**
	 * Column name mapping.
	 *
	 * @var array
	 */
	private $names = [
		self::COUNTRY_CODE         => 'countryCode',
		self::COUNTRY_NAME         => 'countryName',
		self::REGION_NAME          => 'regionName',
		self::CITY_NAME            => 'cityName',
		self::LATITUDE             => 'latitude',
		self::LONGITUDE            => 'longitude',
		self::ISP                  => 'isp',
		self::DOMAIN_NAME          => 'domainName',
		self::ZIP_CODE             => 'zipCode',
		self::TIME_ZONE            => 'timeZone',
		self::NET_SPEED            => 'netSpeed',
		self::IDD_CODE             => 'iddCode',
		self::AREA_CODE            => 'areaCode',
		self::WEATHER_STATION_CODE => 'weatherStationCode',
		self::WEATHER_STATION_NAME => 'weatherStationName',
		self::MCC                  => 'mcc',
		self::MNC                  => 'mnc',
		self::MOBILE_CARRIER_NAME  => 'mobileCarrierName',
		self::ELEVATION            => 'elevation',
		self::USAGE_TYPE           => 'usageType',
		self::ADDRESS_TYPE         => 'addressType',
		self::CATEGORY             => 'category',
		self::IP_ADDRESS           => 'ipAddress',
		self::IP_VERSION           => 'ipVersion',
		self::IP_NUMBER            => 'ipNumber',
	];

	/**
	 * Database names, in order of preference for file lookup.
	 *
	 * @var array
	 */
	private $databases = [
		// IPv4 databases
		'IP-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ZIPCODE-TIMEZONE-ISP-DOMAIN-NETSPEED-AREACODE-WEATHER-MOBILE-ELEVATION-USAGETYPE-ADDRESSTYPE-CATEGORY',
		'IP-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ZIPCODE-TIMEZONE-ISP-DOMAIN-NETSPEED-AREACODE-WEATHER-MOBILE-ELEVATION-USAGETYPE',
		'IP-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ISP-DOMAIN-MOBILE-USAGETYPE',
		'IP-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ZIPCODE-TIMEZONE-ISP-DOMAIN-NETSPEED-AREACODE-WEATHER-MOBILE-ELEVATION',
		'IP-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ZIPCODE-TIMEZONE-AREACODE-ELEVATION',
		'IP-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ZIPCODE-TIMEZONE-ISP-DOMAIN-NETSPEED-AREACODE-WEATHER-MOBILE',
		'IP-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ISP-DOMAIN-MOBILE',
		'IP-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ZIPCODE-TIMEZONE-ISP-DOMAIN-NETSPEED-AREACODE-WEATHER',
		'IP-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ZIPCODE-TIMEZONE-NETSPEED-WEATHER',
		'IP-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ZIPCODE-TIMEZONE-ISP-DOMAIN-NETSPEED-AREACODE',
		'IP-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ZIPCODE-TIMEZONE-AREACODE',
		'IP-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ZIPCODE-TIMEZONE-ISP-DOMAIN-NETSPEED',
		'IP-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-TIMEZONE-NETSPEED',
		'IP-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ZIPCODE-TIMEZONE-ISP-DOMAIN',
		'IP-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ZIPCODE-TIMEZONE',
		'IP-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ZIPCODE-ISP-DOMAIN',
		'IP-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ZIPCODE',
		'IP-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ISP-DOMAIN',
		'IP-COUNTRY-REGION-CITY-ISP-DOMAIN',
		'IP-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ISP',
		'IP-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE',
		'IP-COUNTRY-REGION-CITY-ISP',
		'IP-COUNTRY-REGION-CITY',
		'IP-COUNTRY-ISP',
		'IP-COUNTRY',

		// IPv6 databases
		'IPV6-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ZIPCODE-TIMEZONE-ISP-DOMAIN-NETSPEED-AREACODE-WEATHER-MOBILE-ELEVATION-USAGETYPE-ADDRESSTYPE-CATEGORY',
		'IPV6-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ZIPCODE-TIMEZONE-ISP-DOMAIN-NETSPEED-AREACODE-WEATHER-MOBILE-ELEVATION-USAGETYPE',
		'IPV6-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ISP-DOMAIN-MOBILE-USAGETYPE',
		'IPV6-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ZIPCODE-TIMEZONE-ISP-DOMAIN-NETSPEED-AREACODE-WEATHER-MOBILE-ELEVATION',
		'IPV6-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ZIPCODE-TIMEZONE-AREACODE-ELEVATION',
		'IPV6-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ZIPCODE-TIMEZONE-ISP-DOMAIN-NETSPEED-AREACODE-WEATHER-MOBILE',
		'IPV6-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ISP-DOMAIN-MOBILE',
		'IPV6-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ZIPCODE-TIMEZONE-ISP-DOMAIN-NETSPEED-AREACODE-WEATHER',
		'IPV6-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ZIPCODE-TIMEZONE-NETSPEED-WEATHER',
		'IPV6-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ZIPCODE-TIMEZONE-ISP-DOMAIN-NETSPEED-AREACODE',
		'IPV6-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ZIPCODE-TIMEZONE-AREACODE',
		'IPV6-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ZIPCODE-TIMEZONE-ISP-DOMAIN-NETSPEED',
		'IPV6-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-TIMEZONE-NETSPEED',
		'IPV6-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ZIPCODE-TIMEZONE-ISP-DOMAIN',
		'IPV6-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ZIPCODE-TIMEZONE',
		'IPV6-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ZIPCODE-ISP-DOMAIN',
		'IPV6-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ZIPCODE',
		'IPV6-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ISP-DOMAIN',
		'IPV6-COUNTRY-REGION-CITY-ISP-DOMAIN',
		'IPV6-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE-ISP',
		'IPV6-COUNTRY-REGION-CITY-LATITUDE-LONGITUDE',
		'IPV6-COUNTRY-REGION-CITY-ISP',
		'IPV6-COUNTRY-REGION-CITY',
		'IPV6-COUNTRY-ISP',
		'IPV6-COUNTRY',
	];

	/**
	 * Memory buffer to use for MEMORY_CACHE mode, the keys will be BIN filenames and the values their contents.
	 *
	 * @var array
	 */
	private $buffer = [];

	/**
	 * The machine's float size.
	 *
	 * @var int
	 */
	private $floatSize = null;

	/**
	 * The configured memory limit.
	 *
	 * @var int
	 */
	private $memoryLimit = null;

	/**
	 * Caching mode to use (one of FILE_IO, MEMORY_CACHE, or SHARED_MEMORY).
	 *
	 * @var int
	 */
	private $mode;

	/**
	 * File pointer to use for FILE_IO mode, BIN filename for MEMORY_CACHE mode, or shared memory id to use for SHARED_MEMORY mode.
	 *
	 * @var false|int|resource
	 */
	private $resource = false;

	/**
	 * Database's compilation date.
	 *
	 * @var int
	 */
	private $date;

	/**
	 * Database's type (0--23).
	 *
	 * @var int
	 */
	private $type;

	/**
	 * Database's register width (as an array mapping 4 to IPv4 width, and 6 to IPv6 width).
	 *
	 * @var array
	 */
	private $columnWidth = [];

	/**
	 * Database's pointer offset (as an array mapping 4 to IPv4 offset, and 6 to IPv6 offset).
	 *
	 * @var array
	 */
	private $offset = [];

	/**
	 * Amount of IP address ranges the database contains (as an array mapping 4 to IPv4 count, and 6 to IPv6 count).
	 *
	 * @var array
	 */
	private $ipCount = [];

	/**
	 * Offset withing the database where IP data begins (as an array mapping 4 to IPv4 base, and 6 to IPv6 base).
	 *
	 * @var array
	 */
	private $ipBase = [];

	/**
	 * Base index address.
	 *
	 * @var array
	 */
	private $indexBaseAddr = [];

	/**
	 * The year of the database is released.
	 *
	 * @var string
	 */
	private $year;

	/**
	 * The month of the database is released.
	 *
	 * @var string
	 */
	private $month;

	/**
	 * The day of the database is released.
	 *
	 * @var string
	 */
	private $day;

	/**
	 * Product code.
	 *
	 * @var string
	 */
	private $productCode;

	/**
	 * License code.
	 *
	 * @var string
	 */
	private $licenseCode;

	/**
	 * Database size.
	 *
	 * @var int
	 */
	private $databaseSize;

	/**
	 * The raw row of column positions.
	 *
	 * @var string
	 */
	private $rawPositionsRow;

	/**
	 * IP2Location web service API key.
	 *
	 * @var string
	 */
	private $apiKey;

	/**
	 * Web service package.
	 *
	 * @var string
	 */
	private $package;

	/**
	 * Either use HTTPS or HTTP.
	 *
	 * @var bool
	 */
	private $useSsl;

	/**
	 * Add ons used by the web service.
	 *
	 * @var array
	 */
	private $addOns = [];

	/**
	 * Default fields to return during lookup.
	 *
	 * @var array|int
	 */
	private $defaultFields = self::ALL;

	/**
	 * Constructor.
	 *
	 * @param string $file          Filename of the BIN database to load
	 * @param int    $mode          Caching mode (FILE_IO, MEMORY_CACHE, or SHARED_MEMORY)
	 * @param mixed  $defaultFields
	 *
	 * @throws \Exception
	 */
	public function __construct($file = null, $mode = self::FILE_IO, $defaultFields = self::ALL)
	{
		// Locate the actual file
		$realPath = $this->findFile($file);

		// File size
		$fileSize = filesize($realPath);

		// initialize caching backend
		switch ($mode) {
			case self::SHARED_MEMORY:
				// Make sure shmop extension is loaded
				if (!\extension_loaded('shmop')) {
					throw new \Exception(__CLASS__ . ": Please make sure your PHP setup has the 'shmop' extension enabled.", self::EXCEPTION_NO_SHMOP);
				}

				$memoryLimit = $this->getMemoryLimit();

				if ($memoryLimit !== false && $fileSize > $memoryLimit) {
					throw new \Exception(__CLASS__ . ": Insufficient memory to load file '{$realPath}'.", self::EXCEPTION_NO_MEMORY);
				}

				$this->mode = self::SHARED_MEMORY;
				$shmKey = $this->getShmKey($realPath);
				$fileSizeChanged = false;

				// Open shared memory segment
				$this->resource = @shmop_open($shmKey, 'a', 0, 0);

				// Segment does not exist or file size changed
				if ($this->resource === false || $fileSizeChanged = (shmop_size($this->resource) !== filesize($realPath))) {
					// File size has changed, remove old segment
					if ($fileSizeChanged && !shmop_delete($this->resource)) {
						throw new \Exception(__CLASS__ . ": Unable to delete shared memory block '{$shmKey}'.", self::EXCEPTION_SHMOP_DELETE_FAILED);
					}

					$fp = fopen($realPath, 'r');

					if ($fp === false) {
						throw new \Exception(__CLASS__ . ": Unable to open file '{$realPath}'.", self::EXCEPTION_FILE_OPEN_FAILED);
					}

					// Open the memory segment for exclusive access
					$shmId = @shmop_open($shmKey, 'n', self::SHM_PERMS, $fileSize);

					if ($shmId === false) {
						throw new \Exception(__CLASS__ . ": Unable to create shared memory block '{$shmKey}'.", self::EXCEPTION_SHMOP_CREATE_FAILED);
					}

					// Load SHM_CHUNK_SIZE bytes at a time
					$pointer = 0;
					while ($pointer < $fileSize) {
						$buffer = fread($fp, self::SHM_CHUNK_SIZE);
						shmop_write($shmId, $buffer, $pointer);
						$pointer += self::SHM_CHUNK_SIZE;
					}

					if (PHP_MAJOR_VERSION < 8) {
						shmop_close($shmId);
					}

					fclose($fp);

					// Open memory segment for readonly access
					$this->resource = @shmop_open($shmKey, 'a', 0, 0);

					if ($this->resource === false) {
						throw new \Exception(__CLASS__ . ": Unable to access shared memory block '{$shmKey}' for reading.", self::EXCEPTION_SHMOP_READING_FAILED);
					}
				}
				break;

			case self::MEMORY_CACHE:
				$this->mode = self::MEMORY_CACHE;
				$this->resource = $realPath;

				if (!\array_key_exists($realPath, $this->buffer)) {
					$memoryLimit = $this->getMemoryLimit();

					if ($memoryLimit !== false && $fileSize > $memoryLimit) {
						throw new \Exception(__CLASS__ . ": Insufficient memory to load file '{$realPath}'.", self::EXCEPTION_NO_MEMORY);
					}

					$this->buffer[$realPath] = @file_get_contents($realPath);

					if ($this->buffer[$realPath] === false) {
						throw new \Exception(__CLASS__ . ": Unable to open file '{$realPath}'.", self::EXCEPTION_FILE_OPEN_FAILED);
					}
				}
				break;

			case self::FILE_IO:
			default:
				$this->mode = self::FILE_IO;
				$this->resource = @fopen($realPath, 'r');
				if ($this->resource === false) {
					throw new \Exception(__CLASS__ . ": Unable to open file '{$realPath}'.", self::EXCEPTION_FILE_OPEN_FAILED);
				}
				break;
		}

		// Determine platform's float size
		if ($this->floatSize === null) {
			$this->floatSize = \strlen(pack('f', M_PI));
		}

		// Set default return fields
		$this->defaultFields = $defaultFields;

		// Read metadata headers from the first 512 bytes
		$headers = $this->read(0, 512);

		// Extract metadata from headers
		$this->type = unpack('C', $headers, 0)[1] - 1;
		$this->columnWidth[4] = unpack('C', $headers, 1)[1] * 4;
		$this->columnWidth[6] = $this->columnWidth[4] + 12;
		$this->offset[4] = -4;
		$this->offset[6] = 8;
		$this->year = 2000 + unpack('C', $headers, 2)[1];
		$this->month = unpack('C', $headers, 3)[1];
		$this->day = unpack('C', $headers, 4)[1];
		$this->date = date('Y-m-d', strtotime("{$this->year}-{$this->month}-{$this->day}"));
		$this->productCode = unpack('C', $headers, 29)[1];
		$this->licenseCode = unpack('C', $headers, 30)[1];
		$this->databaseSize = unpack('C', $headers, 31)[1];
		$this->ipCount[4] = unpack('V', $headers, 5)[1];
		$this->ipBase[4] = unpack('V', $headers, 9)[1];
		$this->ipCount[6] = unpack('V', $headers, 13)[1];
		$this->ipBase[6] = unpack('V', $headers, 17)[1];
		$this->indexBaseAddr[4] = unpack('V', $headers, 21)[1];
		$this->indexBaseAddr[6] = unpack('V', $headers, 25)[1];

		if ($this->productCode == 0) {
			throw new \Exception(__CLASS__ . ': Incorrect IP2Location BIN file format. Please make sure that you are using the latest IP2Location BIN file.', self::EXCEPTION_INVALID_BIN_DATABASE);
		}
	}

	/**
	 * Destructor.
	 */
	public function __destruct()
	{
		switch ($this->mode) {
			case self::FILE_IO:
				// Free the file pointer
				if ($this->resource !== false) {
					fclose($this->resource);
					$this->resource = false;
				}
				break;

			case self::SHARED_MEMORY:
				// Detach from the memory segment
				if ($this->resource !== false) {
					if (PHP_MAJOR_VERSION < 8) {
						shmop_close($this->resource);
					}

					$this->resource = false;
				}
				break;
		}
	}

	/**
	 * Tear down a shared memory segment created for the given file.
	 *
	 * @param string $file Filename of the BIN database
	 *
	 * @throws \Exception
	 */
	public function shmTeardown($file)
	{
		// Make sure shmop extension is loaded
		if (!\extension_loaded('shmop')) {
			throw new \Exception(__CLASS__ . ": Please make sure your PHP setup has the 'shmop' extension enabled.", self::EXCEPTION_NO_SHMOP);
		}

		// Get actual file path
		$realPath = realpath($file);

		// Throw error if file cannot be located
		if ($realPath === false) {
			throw new \Exception(__CLASS__ . ": Database file '{$file}' does not seem to exist.", self::EXCEPTION_DATABASE_FILE_NOT_FOUND);
		}

		$shmKey = $this->getShmKey($realPath);

		// Open the memory segment for writing
		$shmId = @shmop_open($shmKey, 'w', 0, 0);

		if ($shmId === false) {
			throw new \Exception(__CLASS__ . ": Unable to access shared memory block '{$shmKey}' for writing.", self::EXCEPTION_SHMOP_WRITING_FAILED);
		}

		// Delete the memory segment
		shmop_delete($shmId);
	}

	/**
	 * Get the database's compilation date as a string of the form 'YYYY-MM-DD'.
	 *
	 * @return string
	 */
	public function getDate()
	{
		return $this->date;
	}

	/**
	 * Get the database's type (1 - 25).
	 *
	 * @return int
	 */
	public function getType()
	{
		return $this->type + 1;
	}

	/**
	 * Return fields available in current database.
	 *
	 * @param bool $asNames Whether to return the mapped names instead of numbered constants
	 *
	 * @return array
	 */
	public function getFields($asNames = false)
	{
		$result = array_keys(array_filter($this->columns, function ($field) {
			return $field[$this->type] !== 0;
		}));

		if ($asNames) {
			$return = [];
			foreach ($result as $field) {
				$return[] = $this->names[$field];
			}

			return $return;
		}

		return $result;
	}

	/**
	 * Return the version of module.
	 */
	public function getModuleVersion()
	{
		return self::VERSION;
	}

	/**
	 * Return the version of current database.
	 */
	public function getDatabaseVersion()
	{
		return $this->year . '.' . $this->month . '.' . $this->day;
	}

	/**
	 * This function will look the given IP address up in the database and return the result(s) asked for.
	 *
	 * If a single, SINGULAR, field is specified, only its mapped value is returned.
	 * If many fields are given (as an array) or a MULTIPLE field is specified, an
	 * array with the returned singular field names as keys and their corresponding
	 * values is returned.
	 *
	 * @param string    $ip      IP address to look up
	 * @param array|int $fields  Field(s) to return
	 * @param bool      $asNamed Whether to return an associative array instead
	 *
	 * @return array|bool|mixed
	 */
	public function lookup($ip, $fields = null, $asNamed = true)
	{
		// Get IP version and number
		list($ipVersion, $ipNumber) = $this->ipVersionAndNumber($ip);

		// Perform a binary search
		$pointer = $this->binSearch($ipVersion, $ipNumber);

		if (empty($pointer)) {
			return false;
		}

		// Apply default fields
		if ($fields === null) {
			$fields = $this->defaultFields;
		}

		// Get the entire row based on the pointer value
		if ($ipVersion === 4) {
			$this->rawPositionsRow = $this->read($pointer - 1, $this->columnWidth[4] + 4);
		} elseif ($ipVersion === 6) {
			$this->rawPositionsRow = $this->read($pointer - 1, $this->columnWidth[6]);
		}

		// turn fields into an array in case it wasn't already
		$ifields = (array) $fields;

		// add fields if needed
		if (\in_array(self::ALL, $ifields)) {
			$ifields[] = self::REGION_NAME;
			$ifields[] = self::CITY_NAME;
			$ifields[] = self::ISP;
			$ifields[] = self::DOMAIN_NAME;
			$ifields[] = self::ZIP_CODE;
			$ifields[] = self::TIME_ZONE;
			$ifields[] = self::NET_SPEED;
			$ifields[] = self::ELEVATION;
			$ifields[] = self::USAGE_TYPE;
			$ifields[] = self::ADDRESS_TYPE;
			$ifields[] = self::CATEGORY;

			$ifields[] = self::COUNTRY;
			$ifields[] = self::COORDINATES;
			$ifields[] = self::IDD_AREA;
			$ifields[] = self::WEATHER_STATION;
			$ifields[] = self::MCC_MNC_MOBILE_CARRIER_NAME;

			$ifields[] = self::IP_ADDRESS;
			$ifields[] = self::IP_VERSION;
			$ifields[] = self::IP_NUMBER;
		}
		// turn into a uniquely-valued array the fast way
		// (see: http://php.net/manual/en/function.array-unique.php#77743)
		$afields = array_keys(array_flip($ifields));
		// sorting them in reverse order warrants that by the time we get to
		// SINGULAR fields, its MULTIPLE counterparts, if at all present, have
		// already been retrieved
		rsort($afields);

		// maintain a list of already retrieved fields to avoid doing it twice
		$done = [
			self::COUNTRY_CODE                => false,
			self::COUNTRY_NAME                => false,
			self::REGION_NAME                 => false,
			self::CITY_NAME                   => false,
			self::LATITUDE                    => false,
			self::LONGITUDE                   => false,
			self::ISP                         => false,
			self::DOMAIN_NAME                 => false,
			self::ZIP_CODE                    => false,
			self::TIME_ZONE                   => false,
			self::NET_SPEED                   => false,
			self::IDD_CODE                    => false,
			self::AREA_CODE                   => false,
			self::WEATHER_STATION_CODE        => false,
			self::WEATHER_STATION_NAME        => false,
			self::MCC                         => false,
			self::MNC                         => false,
			self::MOBILE_CARRIER_NAME         => false,
			self::ELEVATION                   => false,
			self::USAGE_TYPE                  => false,
			self::ADDRESS_TYPE                => false,
			self::CATEGORY                    => false,
			self::COUNTRY                     => false,
			self::COORDINATES                 => false,
			self::IDD_AREA                    => false,
			self::WEATHER_STATION             => false,
			self::MCC_MNC_MOBILE_CARRIER_NAME => false,
			self::IP_ADDRESS                  => false,
			self::IP_VERSION                  => false,
			self::IP_NUMBER                   => false,
		];

		$results = [];

		// treat each field in turn
		foreach ($afields as $afield) {
			switch ($afield) {
				// purposefully ignore self::ALL, we already dealt with it
				case self::ALL:
					break;

				case self::COUNTRY:
					if (!$done[self::COUNTRY]) {
						list($results[self::COUNTRY_NAME], $results[self::COUNTRY_CODE]) = $this->readCountryNameAndCode($pointer);
						$done[self::COUNTRY] = true;
						$done[self::COUNTRY_CODE] = true;
						$done[self::COUNTRY_NAME] = true;
					}
					break;

				case self::COORDINATES:
					if (!$done[self::COORDINATES]) {
						list($results[self::LATITUDE], $results[self::LONGITUDE]) = $this->readLatitudeAndLongitude($pointer);
						$done[self::COORDINATES] = true;
						$done[self::LATITUDE] = true;
						$done[self::LONGITUDE] = true;
					}
					break;

				case self::IDD_AREA:
					if (!$done[self::IDD_AREA]) {
						list($results[self::IDD_CODE], $results[self::AREA_CODE]) = $this->readIddAndAreaCodes($pointer);
						$done[self::IDD_AREA] = true;
						$done[self::IDD_CODE] = true;
						$done[self::AREA_CODE] = true;
					}
					break;

				case self::WEATHER_STATION:
					if (!$done[self::WEATHER_STATION]) {
						list($results[self::WEATHER_STATION_NAME], $results[self::WEATHER_STATION_CODE]) = $this->readWeatherStationNameAndCode($pointer);
						$done[self::WEATHER_STATION] = true;
						$done[self::WEATHER_STATION_NAME] = true;
						$done[self::WEATHER_STATION_CODE] = true;
					}
					break;
				case self::MCC_MNC_MOBILE_CARRIER_NAME:
					if (!$done[self::MCC_MNC_MOBILE_CARRIER_NAME]) {
						list($results[self::MCC], $results[self::MNC], $results[self::MOBILE_CARRIER_NAME]) = $this->readMccMncAndMobileCarrierName($pointer);
						$done[self::MCC_MNC_MOBILE_CARRIER_NAME] = true;
						$done[self::MCC] = true;
						$done[self::MNC] = true;
						$done[self::MOBILE_CARRIER_NAME] = true;
					}
					break;

				case self::COUNTRY_CODE:
					if (!$done[self::COUNTRY_CODE]) {
						$results[self::COUNTRY_CODE] = $this->readCountryNameAndCode($pointer)[1];
						$done[self::COUNTRY_CODE] = true;
					}
					break;

				case self::COUNTRY_NAME:
					if (!$done[self::COUNTRY_NAME]) {
						$results[self::COUNTRY_NAME] = $this->readCountryNameAndCode($pointer)[0];
						$done[self::COUNTRY_NAME] = true;
					}
					break;

				case self::REGION_NAME:
					if (!$done[self::REGION_NAME]) {
						$results[self::REGION_NAME] = $this->readRegionName($pointer);
						$done[self::REGION_NAME] = true;
					}
					break;

				case self::CITY_NAME:
					if (!$done[self::CITY_NAME]) {
						$results[self::CITY_NAME] = $this->readCityName($pointer);
						$done[self::CITY_NAME] = true;
					}
					break;

				case self::LATITUDE:
					if (!$done[self::LATITUDE]) {
						$results[self::LATITUDE] = $this->readLatitudeAndLongitude($pointer)[0];
						$done[self::LATITUDE] = true;
					}
					break;

				case self::LONGITUDE:
					if (!$done[self::LONGITUDE]) {
						$results[self::LONGITUDE] = $this->readLatitudeAndLongitude($pointer)[1];
						$done[self::LONGITUDE] = true;
					}
					break;

				case self::ISP:
					if (!$done[self::ISP]) {
						$results[self::ISP] = $this->readIsp($pointer);
						$done[self::ISP] = true;
					}
					break;

				case self::DOMAIN_NAME:
					if (!$done[self::DOMAIN_NAME]) {
						$results[self::DOMAIN_NAME] = $this->readDomainName($pointer);
						$done[self::DOMAIN_NAME] = true;
					}
					break;

				case self::ZIP_CODE:
					if (!$done[self::ZIP_CODE]) {
						$results[self::ZIP_CODE] = $this->readZipCode($pointer);
						$done[self::ZIP_CODE] = true;
					}
					break;

				case self::TIME_ZONE:
					if (!$done[self::TIME_ZONE]) {
						$results[self::TIME_ZONE] = $this->readTimeZone($pointer);
						$done[self::TIME_ZONE] = true;
					}
					break;

				case self::NET_SPEED:
					if (!$done[self::NET_SPEED]) {
						$results[self::NET_SPEED] = $this->readNetSpeed($pointer);
						$done[self::NET_SPEED] = true;
					}
					break;

				case self::IDD_CODE:
					if (!$done[self::IDD_CODE]) {
						$results[self::IDD_CODE] = $this->readIddAndAreaCodes($pointer)[0];
						$done[self::IDD_CODE] = true;
					}
					break;

				case self::AREA_CODE:
					if (!$done[self::AREA_CODE]) {
						$results[self::AREA_CODE] = $this->readIddAndAreaCodes($pointer)[1];
						$done[self::AREA_CODE] = true;
					}
					break;

				case self::WEATHER_STATION_CODE:
					if (!$done[self::WEATHER_STATION_CODE]) {
						$results[self::WEATHER_STATION_CODE] = $this->readWeatherStationNameAndCode($pointer)[1];
						$done[self::WEATHER_STATION_CODE] = true;
					}
					break;

				case self::WEATHER_STATION_NAME:
					if (!$done[self::WEATHER_STATION_NAME]) {
						$results[self::WEATHER_STATION_NAME] = $this->readWeatherStationNameAndCode($pointer)[0];
						$done[self::WEATHER_STATION_NAME] = true;
					}
					break;

				case self::MCC:
					if (!$done[self::MCC]) {
						$results[self::MCC] = $this->readMccMncAndMobileCarrierName($pointer)[0];
						$done[self::MCC] = true;
					}
					break;

				case self::MNC:
					if (!$done[self::MNC]) {
						$results[self::MNC] = $this->readMccMncAndMobileCarrierName($pointer)[1];
						$done[self::MNC] = true;
					}
					break;

				case self::MOBILE_CARRIER_NAME:
					if (!$done[self::MOBILE_CARRIER_NAME]) {
						$results[self::MOBILE_CARRIER_NAME] = $this->readMccMncAndMobileCarrierName($pointer)[2];
						$done[self::MOBILE_CARRIER_NAME] = true;
					}
					break;

				case self::ELEVATION:
					if (!$done[self::ELEVATION]) {
						$results[self::ELEVATION] = $this->readElevation($pointer);
						$done[self::ELEVATION] = true;
					}
					break;

				case self::USAGE_TYPE:
					if (!$done[self::USAGE_TYPE]) {
						$results[self::USAGE_TYPE] = $this->readUsageType($pointer);
						$done[self::USAGE_TYPE] = true;
					}
					break;

				case self::ADDRESS_TYPE:
					if (!$done[self::ADDRESS_TYPE]) {
						$results[self::ADDRESS_TYPE] = $this->readAddressType($pointer);
						$done[self::ADDRESS_TYPE] = true;
					}
					break;

				case self::CATEGORY:
					if (!$done[self::CATEGORY]) {
						$results[self::CATEGORY] = $this->readCategory($pointer);
						$done[self::CATEGORY] = true;
					}
					break;

				case self::IP_ADDRESS:
					if (!$done[self::IP_ADDRESS]) {
						$results[self::IP_ADDRESS] = $ip;
						$done[self::IP_ADDRESS] = true;
					}
					break;

				case self::IP_VERSION:
					if (!$done[self::IP_VERSION]) {
						$results[self::IP_VERSION] = $ipVersion;
						$done[self::IP_VERSION] = true;
					}
					break;

				case self::IP_NUMBER:
					if (!$done[self::IP_NUMBER]) {
						$results[self::IP_NUMBER] = $ipNumber;
						$done[self::IP_NUMBER] = true;
					}
					break;

				default:
					$results[$afield] = self::FIELD_NOT_KNOWN;
			}
		}

		// If we were asked for an array, or we have multiple results to return...
		if (\is_array($fields) || \count($results) > 1) {
			// return array
			if ($asNamed) {
				// apply translations if needed
				$return = [];
				foreach ($results as $key => $val) {
					if (\array_key_exists($key, $this->names)) {
						$return[$this->names[$key]] = $val;
					} else {
						$return[$key] = $val;
					}
				}

				return $return;
			}

			return $results;
		}
		// return a single value
		return array_values($results)[0];
	}

	/**
	 * For a given IP address, returns the cidr of his sub-network.
	 *
	 * @param string $ip
	 *
	 * @return array
	 * */
	public function getCidr($ip)
	{
		// Extract IP version and number
		list($ipVersion, $ipNumber) = $this->ipVersionAndNumber($ip);

		// Perform the binary search proper (if the IP address was invalid, binSearch will return false)
		$records = $this->binSearch($ipVersion, $ipNumber, true);
		if (!empty($records)) {
			$result = [];

			list($ipFrom, $ipTo) = $records;

			--$ipTo;

			while ($ipTo >= $ipFrom) {
				$maxSize = $this->getMaxSize($ipFrom, 32);
				$x = log($ipTo - $ipFrom + 1) / log(2);
				$maxDiff = floor(32 - floor($x));

				$ip = long2ip($ipFrom);

				if ($maxSize < $maxDiff) {
					$maxSize = $maxDiff;
				}

				$result[] = $ip . '/' . $maxSize;
				$ipFrom += pow(2, (32 - $maxSize));
			}

			return $result;
		}

		return false;
	}

	/**
	 * Get maximum size of a net block.
	 *
	 * @param int $base The base number
	 * @param int $bit  The bit number
	 *
	 * @return bool|int
	 */
	private function getMaxSize($base, $bit)
	{
		while ($bit > 0) {
			$decimal = hexdec(base_convert((pow(2, 32) - pow(2, (32 - ($bit - 1)))), 10, 16));

			if (($base & $decimal) != $base) {
				break;
			}
			--$bit;
		}

		return $bit;
	}

	/**
	 * Get memory limit from the current PHP settings (return false if no memory limit set).
	 *
	 * @return bool|int
	 */
	private function getMemoryLimit()
	{
		// Get values if no cache
		if ($this->memoryLimit === null) {
			$memoryLimit = ini_get('memory_limit');

			// Default memory limit
			if ((string) $memoryLimit === '') {
				$memoryLimit = '128M';
			}

			$value = (int) $memoryLimit;

			// Deal with "no-limit"
			if ($value < 0) {
				$value = false;
			} else {
				// Deal with shorthand bytes
				switch (strtoupper(substr($memoryLimit, -1))) {
					case 'G': $value *= 1024;
					// no break
					case 'M': $value *= 1024;
					// no break
					case 'K': $value *= 1024;
				}
			}

			$this->memoryLimit = $value;
		}

		return $this->memoryLimit;
	}

	/**
	 * Return the realpath of the given file or look for the first matching database option.
	 *
	 * @param string $file File to try to find, or null to try the databases in turn on the current file's path
	 *
	 * @throws \Exception
	 *
	 * @return string
	 */
	private function findFile($file = null)
	{
		if ($file !== null) {
			// Get actual file path
			$realPath = realpath($file);

			// Throw error if file cannot be located
			if ($realPath === false) {
				throw new \Exception(__CLASS__ . ": Database file '{$file}' does not seem to exist.", self::EXCEPTION_DATABASE_FILE_NOT_FOUND);
			}

			return $realPath;
		}

		// Try to get current path
		$current = realpath(__DIR__);

		if ($current === false) {
			throw new \Exception(__CLASS__ . ': Cannot determine current path.', self::EXCEPTION_NO_PATH);
		}

		// Try each database in turn
		foreach ($this->databases as $database) {
			$realPath = realpath("{$current}/{$database}.BIN");

			if ($realPath !== false) {
				return $realPath;
			}
		}

		// No candidates found
		throw new \Exception(__CLASS__ . ': No candidate database files found.', self::EXCEPTION_NO_CANDIDATES);
	}

	/**
	 * Make the given number positive by wrapping it to 8 bit values.
	 *
	 * @param int $x Number to wrap
	 *
	 * @return int
	 */
	private function wrap8($x)
	{
		return $x + ($x < 0 ? 256 : 0);
	}

	/**
	 * Make the given number positive by wrapping it to 32 bit values.
	 *
	 * @param int $x Number to wrap
	 *
	 * @return int
	 */
	private function wrap32($x)
	{
		return $x + ($x < 0 ? 4294967296 : 0);
	}

	/**
	 * Generate a unique and repeatable shared memory key for each instance to use.
	 *
	 * @param string $filename Filename of the BIN file
	 *
	 * @return int
	 */
	private function getShmKey($filename)
	{
		return (int) sprintf('%u', $this->wrap32(crc32(__FILE__ . ':' . $filename)));
	}

	/**
	 * Determine whether the given IP number of the given version lies between the given bounds.
	 *
	 * This function will return 0 if the given ip number falls within the given bounds
	 * for the given version, -1 if it falls below, and 1 if it falls above.
	 *
	 * @param int        $version IP version to use (either 4 or 6)
	 * @param int|string $ip      IP number to check (int for IPv4, string for IPv6)
	 * @param int|string $low     Lower bound (int for IPv4, string for IPv6)
	 * @param int|string $high    Uppoer bound (int for IPv4, string for IPv6)
	 *
	 * @return int
	 */
	private function ipBetween($version, $ip, $low, $high)
	{
		if ($version === 4) {
			// Use normal PHP ints
			if ($low <= $ip) {
				if ($ip < $high) {
					return 0;
				}

				return 1;
			}

			return -1;
		}
		// Use BCMath
		if (bccomp($low, $ip, 0) <= 0) {
			if (bccomp($ip, $high, 0) <= -1) {
				return 0;
			}

			return 1;
		}

		return -1;
	}

	/**
	 * Get the IP version and number of the given IP address.
	 *
	 * @param string $ip IP address to extract the version and number for
	 *
	 * @return array
	 */
	private function ipVersionAndNumber($ip)
	{
		if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			$number = sprintf('%u', ip2long($ip));

			return [4, ($number == self::MAX_IPV4_RANGE) ? ($number - 1) : $number];
		} elseif (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
			$result = 0;
			$ip = $this->expand($ip);

			// 6to4 Address - 2002::/16
			if (substr($ip, 0, 4) == '2002') {
				foreach (str_split(bin2hex(inet_pton($ip)), 8) as $word) {
					$result = bcadd(bcmul($result, '4294967296', 0), $this->wrap32(hexdec($word)), 0);
				}

				return [4, bcmod(bcdiv($result, bcpow(2, 80)), '4294967296')];
			}

			// Teredo Address - 2001:0::/32
			if (substr($ip, 0, 9) == '2001:0000' && str_replace(':', '', substr($ip, -9)) != '00000000') {
				return [4, ip2long(long2ip(~hexdec(str_replace(':', '', substr($ip, -9)))))];
			}

			foreach (str_split(bin2hex(inet_pton($ip)), 8) as $word) {
				$result = bcadd(bcmul($result, '4294967296', 0), $this->wrap32(hexdec($word)), 0);
			}

			// IPv4 address in IPv6
			if (bccomp($result, '281470681743360') >= 0 && bccomp($result, '281474976710655') <= 0) {
				return [4, bcsub($result, '281470681743360')];
			}

			return [6, $result];
		}

		// Invalid IP address, return false
		return [false, false];
	}

	/**
	 * Return the decimal string representing the binary data given.
	 *
	 * @param string $data Binary data to parse
	 *
	 * @return string
	 */
	private function bcBin2Dec($data)
	{
		if (!$data) {
			return;
		}

		$parts = [
			unpack('V', substr($data, 12, 4)),
			unpack('V', substr($data, 8, 4)),
			unpack('V', substr($data, 4, 4)),
			unpack('V', substr($data, 0, 4)),
		];

		foreach ($parts as &$part) {
			if ($part[1] < 0) {
				$part[1] += 4294967296;
			}
		}

		$result = bcadd(bcadd(bcmul($parts[0][1], bcpow(4294967296, 3)), bcmul($parts[1][1], bcpow(4294967296, 2))), bcadd(bcmul($parts[2][1], 4294967296), $parts[3][1]));

		return $result;
	}

	/**
	 * Return the decimal string representing the binary data given.
	 *
	 * @param mixed $ipv6
	 *
	 * @return string
	 */
	private function expand($ipv6)
	{
		$hex = unpack('H*hex', inet_pton($ipv6));

		return substr(preg_replace('/([A-f0-9]{4})/', '$1:', $hex['hex']), 0, -1);
	}

	/**
	 * Low level read function to abstract away the caching mode being used.
	 *
	 * @param int $pos Position from where to start reading
	 * @param int $len Read this many bytes
	 *
	 * @return string
	 */
	private function read($pos, $len)
	{
		switch ($this->mode) {
			case self::SHARED_MEMORY:
				return shmop_read($this->resource, $pos, $len);

			case self::MEMORY_CACHE:
				return $data = substr($this->buffer[$this->resource], $pos, $len);

			default:
				fseek($this->resource, $pos, SEEK_SET);

				return fread($this->resource, $len);
		}
	}

	/**
	 * Low level function to fetch a string from the caching backend.
	 *
	 * @param int $pos        Position to read from
	 * @param int $additional Additional offset to apply
	 *
	 * @return string
	 */
	private function readString($pos, $additional = 0)
	{
		// Get the actual pointer to the string's head by extract from the raw row
		$newPosition = unpack('V', substr($this->rawPositionsRow, $pos, 4))[1] + $additional;

		// Read as much as the length (first "string" byte) indicates
		return $this->read($newPosition + 1, $this->readByte($newPosition + 1));
	}

	/**
	 * Low level function to fetch a float from the caching backend.
	 *
	 * @param int $pos Position to read from
	 *
	 * @return float
	 */
	private function readFloat($pos)
	{
		// Unpack a float's size worth of data
		return unpack('f', substr($this->rawPositionsRow, $pos, $this->floatSize))[1];
	}

	/**
	 * Low level function to fetch a byte from the caching backend.
	 *
	 * @param int $pos Position to read from
	 *
	 * @return string
	 */
	private function readByte($pos)
	{
		// Unpack a byte's worth of data
		return $this->wrap8(unpack('C', $this->read($pos - 1, 1))[1]);
	}

	/**
	 * High level function to fetch the country name and code.
	 *
	 * @param bool|int $pointer Position to read from, if false, return self::INVALID_IP_ADDRESS
	 *
	 * @return array
	 */
	private function readCountryNameAndCode($pointer)
	{
		if ($pointer === false) {
			$countryCode = self::INVALID_IP_ADDRESS;
			$countryName = self::INVALID_IP_ADDRESS;
		} elseif ($this->columns[self::COUNTRY_CODE][$this->type] === 0) {
			$countryCode = self::FIELD_NOT_SUPPORTED;
			$countryName = self::FIELD_NOT_SUPPORTED;
		} else {
			// Read the country code and name (the name shares the country's pointer,
			// but it must be artificially displaced 3 bytes ahead: 2 for the country code, one
			// for the country name's length)
			$countryCode = $this->readString($this->columns[self::COUNTRY_CODE][$this->type]);
			$countryName = $this->readString($this->columns[self::COUNTRY_NAME][$this->type], 3);
		}

		return [$countryName, $countryCode];
	}

	/**
	 * High level function to fetch the region name.
	 *
	 * @param int $pointer Position to read from, if false, return self::INVALID_IP_ADDRESS
	 *
	 * @return string
	 */
	private function readRegionName($pointer)
	{
		if ($pointer === false) {
			return self::INVALID_IP_ADDRESS;
		}

		if ($this->columns[self::REGION_NAME][$this->type] === 0) {
			return self::FIELD_NOT_SUPPORTED;
		}

		return $this->readString($this->columns[self::REGION_NAME][$this->type]);
	}

	/**
	 * High level function to fetch the city name.
	 *
	 * @param int $pointer Position to read from, if false, return self::INVALID_IP_ADDRESS
	 *
	 * @return string
	 */
	private function readCityName($pointer)
	{
		if ($pointer === false) {
			return self::INVALID_IP_ADDRESS;
		}

		if ($this->columns[self::CITY_NAME][$this->type] === 0) {
			return self::FIELD_NOT_SUPPORTED;
		}

		return $this->readString($this->columns[self::CITY_NAME][$this->type]);
	}

	/**
	 * High level function to fetch the latitude and longitude.
	 *
	 * @param int $pointer Position to read from, if false, return self::INVALID_IP_ADDRESS
	 *
	 * @return array
	 */
	private function readLatitudeAndLongitude($pointer)
	{
		if ($pointer === false) {
			$latitude = self::INVALID_IP_ADDRESS;
			$longitude = self::INVALID_IP_ADDRESS;
		} elseif ($this->columns[self::LATITUDE][$this->type] === 0) {
			$latitude = self::FIELD_NOT_SUPPORTED;
			$longitude = self::FIELD_NOT_SUPPORTED;
		} else {
			// Read latitude and longitude
			$latitude = round($this->readFloat($this->columns[self::LATITUDE][$this->type]), 6);
			$longitude = round($this->readFloat($this->columns[self::LONGITUDE][$this->type]), 6);
		}

		return [$latitude, $longitude];
	}

	/**
	 * High level function to fetch the ISP name.
	 *
	 * @param int $pointer Position to read from, if false, return self::INVALID_IP_ADDRESS
	 *
	 * @return string
	 */
	private function readIsp($pointer)
	{
		if ($pointer === false) {
			return self::INVALID_IP_ADDRESS;
		}

		if ($this->columns[self::ISP][$this->type] === 0) {
			return self::FIELD_NOT_SUPPORTED;
		}

		return $this->readString($this->columns[self::ISP][$this->type]);
	}

	/**
	 * High level function to fetch the domain name.
	 *
	 * @param int $pointer Position to read from, if false, return self::INVALID_IP_ADDRESS
	 *
	 * @return string
	 */
	private function readDomainName($pointer)
	{
		if ($pointer === false) {
			return self::INVALID_IP_ADDRESS;
		}

		if ($this->columns[self::DOMAIN_NAME][$this->type] === 0) {
			return self::FIELD_NOT_SUPPORTED;
		}

		return $this->readString($this->columns[self::DOMAIN_NAME][$this->type]);
	}

	/**
	 * High level function to fetch the zip code.
	 *
	 * @param int $pointer Position to read from, if false, return self::INVALID_IP_ADDRESS
	 *
	 * @return string
	 */
	private function readZipCode($pointer)
	{
		if ($pointer === false) {
			return self::INVALID_IP_ADDRESS;
		}

		if ($this->columns[self::ZIP_CODE][$this->type] === 0) {
			return self::FIELD_NOT_SUPPORTED;
		}

		return $this->readString($this->columns[self::ZIP_CODE][$this->type]);
	}

	/**
	 * High level function to fetch the time zone.
	 *
	 * @param int $pointer Position to read from, if false, return self::INVALID_IP_ADDRESS
	 *
	 * @return string
	 */
	private function readTimeZone($pointer)
	{
		if ($pointer === false) {
			return self::INVALID_IP_ADDRESS;
		}

		if ($this->columns[self::TIME_ZONE][$this->type] === 0) {
			return self::FIELD_NOT_SUPPORTED;
		}

		return $this->readString($this->columns[self::TIME_ZONE][$this->type]);
	}

	/**
	 * High level function to fetch the net speed.
	 *
	 * @param int $pointer Position to read from, if false, return self::INVALID_IP_ADDRESS
	 *
	 * @return string
	 */
	private function readNetSpeed($pointer)
	{
		if ($pointer === false) {
			return self::INVALID_IP_ADDRESS;
		}

		if ($this->columns[self::NET_SPEED][$this->type] === 0) {
			return self::FIELD_NOT_SUPPORTED;
		}

		return $this->readString($this->columns[self::NET_SPEED][$this->type]);
	}

	/**
	 * High level function to fetch the IDD and area codes.
	 *
	 * @param int $pointer Position to read from, if false, return self::INVALID_IP_ADDRESS
	 *
	 * @return array
	 */
	private function readIddAndAreaCodes($pointer)
	{
		if ($pointer === false) {
			$iddCode = self::INVALID_IP_ADDRESS;
			$areaCode = self::INVALID_IP_ADDRESS;
		} elseif ($this->columns[self::IDD_CODE][$this->type] === 0) {
			$iddCode = self::FIELD_NOT_SUPPORTED;
			$areaCode = self::FIELD_NOT_SUPPORTED;
		} else {
			// Read IDD and area codes
			$iddCode = $this->readString($this->columns[self::IDD_CODE][$this->type]);
			$areaCode = $this->readString($this->columns[self::AREA_CODE][$this->type]);
		}

		return [$iddCode, $areaCode];
	}

	/**
	 * High level function to fetch the weather station name and code.
	 *
	 * @param int $pointer Position to read from, if false, return self::INVALID_IP_ADDRESS
	 *
	 * @return array
	 */
	private function readWeatherStationNameAndCode($pointer)
	{
		if ($pointer === false) {
			$weatherStationName = self::INVALID_IP_ADDRESS;
			$weatherStationCode = self::INVALID_IP_ADDRESS;
		} elseif ($this->columns[self::WEATHER_STATION_NAME][$this->type] === 0) {
			$weatherStationName = self::FIELD_NOT_SUPPORTED;
			$weatherStationCode = self::FIELD_NOT_SUPPORTED;
		} else {
			// Read weather station name and code
			$weatherStationName = $this->readString($this->columns[self::WEATHER_STATION_NAME][$this->type]);
			$weatherStationCode = $this->readString($this->columns[self::WEATHER_STATION_CODE][$this->type]);
		}

		return [$weatherStationName, $weatherStationCode];
	}

	/**
	 * High level function to fetch the MCC, MNC, and mobile carrier name.
	 *
	 * @param int $pointer Position to read from, if false, return self::INVALID_IP_ADDRESS
	 *
	 * @return array
	 */
	private function readMccMncAndMobileCarrierName($pointer)
	{
		if ($pointer === false) {
			$mcc = self::INVALID_IP_ADDRESS;
			$mnc = self::INVALID_IP_ADDRESS;
			$mobileCarrierName = self::INVALID_IP_ADDRESS;
		} elseif ($this->columns[self::MCC][$this->type] === 0) {
			$mcc = self::FIELD_NOT_SUPPORTED;
			$mnc = self::FIELD_NOT_SUPPORTED;
			$mobileCarrierName = self::FIELD_NOT_SUPPORTED;
		} else {
			// Read MCC, MNC, and mobile carrier name
			$mcc = $this->readString($this->columns[self::MCC][$this->type]);
			$mnc = $this->readString($this->columns[self::MNC][$this->type]);
			$mobileCarrierName = $this->readString($this->columns[self::MOBILE_CARRIER_NAME][$this->type]);
		}

		return [$mcc, $mnc, $mobileCarrierName];
	}

	/**
	 * High level function to fetch the elevation.
	 *
	 * @param int $pointer Position to read from, if false, return self::INVALID_IP_ADDRESS
	 *
	 * @return string
	 */
	private function readElevation($pointer)
	{
		if ($pointer === false) {
			return self::INVALID_IP_ADDRESS;
		}

		if ($this->columns[self::ELEVATION][$this->type] === 0) {
			return self::FIELD_NOT_SUPPORTED;
		}

		return $this->readString($this->columns[self::ELEVATION][$this->type]);
	}

	/**
	 * High level function to fetch the usage type.
	 *
	 * @param int $pointer Position to read from, if false, return self::INVALID_IP_ADDRESS
	 *
	 * @return string
	 */
	private function readUsageType($pointer)
	{
		if ($pointer === false) {
			return self::INVALID_IP_ADDRESS;
		}

		if ($this->columns[self::USAGE_TYPE][$this->type] === 0) {
			return self::FIELD_NOT_SUPPORTED;
		}

		return $this->readString($this->columns[self::USAGE_TYPE][$this->type]);
	}

	/**
	 * High level function to fetch the address type.
	 *
	 * @param int $pointer Position to read from, if false, return self::INVALID_IP_ADDRESS
	 *
	 * @return string
	 */
	private function readAddressType($pointer)
	{
		if ($pointer === false) {
			return self::INVALID_IP_ADDRESS;
		}

		if ($this->columns[self::ADDRESS_TYPE][$this->type] === 0) {
			return self::FIELD_NOT_SUPPORTED;
		}

		return $this->readString($this->columns[self::ADDRESS_TYPE][$this->type]);
	}

	/**
	 * High level function to fetch the usage type.
	 *
	 * @param int $pointer Position to read from, if false, return self::INVALID_IP_ADDRESS
	 *
	 * @return string
	 */
	private function readCategory($pointer)
	{
		if ($pointer === false) {
			return self::INVALID_IP_ADDRESS;
		}

		if ($this->columns[self::CATEGORY][$this->type] === 0) {
			return self::FIELD_NOT_SUPPORTED;
		}

		return $this->readString($this->columns[self::CATEGORY][$this->type]);
	}

	/**
	 * Get the boundaries for an IP address.
	 *
	 * @param int $ipVersion IP address version
	 * @param int $position  Lookup position
	 * @param int $width     The section width
	 *
	 * @return array
	 */
	private function getIpBoundary($ipVersion, $position, $width)
	{
		// Read 128 bits from the position
		$section = $this->read($position, 128);

		switch ($ipVersion) {
			case 4:
				return [unpack('V', substr($section, 0, 4))[1], unpack('V', substr($section, $width, 4))[1]];

			case 6:
				return [$this->bcBin2Dec(substr($section, 0, 16)), $this->bcBin2Dec(substr($section, $width, 16))];
		}

		return [false, false];
	}

	/**
	 * Perform a binary search on the given IP number and return a pointer to its record.
	 *
	 * @param int   $version  IP version to use for searching
	 * @param int   $ipNumber IP number to look for
	 * @param mixed $cidr
	 *
	 * @return bool|int
	 */
	private function binSearch($version, $ipNumber, $cidr = false)
	{
		$base = $this->ipBase[$version];
		$offset = $this->offset[$version];
		$width = $this->columnWidth[$version];
		$high = $this->ipCount[$version];
		$low = 0;

		$indexBaseStart = $this->indexBaseAddr[$version];

		if ($indexBaseStart > 1) {
			$indexPos = 0;

			switch ($version) {
				case 4:
					$number = (int) ($ipNumber / 65536);
					$indexPos = $indexBaseStart + ($number << 3);

					break;

				case 6:
					$number = (int) (bcdiv($ipNumber, bcpow('2', '112')));
					$indexPos = $indexBaseStart + ($number << 3);

					break;
			}

			$section = $this->read($indexPos - 1, 8);

			$low = unpack('V', substr($section, 0, 4))[1];
			$high = unpack('V', substr($section, 4, 4))[1];
		}

		// Narrow down the search
		while ($low <= $high) {
			$mid = (int) ($low + (($high - $low) >> 1));
			$position = $base + $width * $mid - 1;

			list($ipStart, $ipEnd) = $this->getIpBoundary($version, $position, $width);

			// Determine whether to return, repeat on the lower half, or repeat on the upper half
			switch ($this->ipBetween($version, $ipNumber, $ipStart, $ipEnd)) {
				case 0:
					return ($cidr) ? [$ipStart, $ipEnd] : $base + $offset + $mid * $width;

				case -1:
					$high = $mid - 1;
					break;

				case 1:
					$low = $mid + 1;
					break;
			}
		}

		// Record not found
		return false;
	}
}
