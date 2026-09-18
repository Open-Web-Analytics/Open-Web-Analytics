# IP2Location Geolocation Module

IP2Location module enabled geolocation lookups using IP2Location BIN database or IP2Location Web Service.



### Installation

1. Download a free IP2Location [DB9 LITE](https://lite.ip2location.com/database/db9-ip-country-region-city-latitude-longitude-zipcode) database. For more accurate data, you may download the commercial version of IP2Locatoin [DB9](https://www.ip2location.com/database/db9-ip-country-region-city-latitude-longitude-zipcode) database.
2. Extract the download package and rename the .BIN database file into **IP2LOCATION.BIN**.
3. Upload IP2LOCATION.BIN into `/owa/owa-data/ip2location/`. Create the directory if does not exist.



### IP2Location Web Service

1. Sign up for [IP2Location Web Service](https://www.ip2location.com/web-service/ip2location).

2. Open `owa-config.php` configuration with a text editor.

3. Enter the following configuration:

   ```php
   $this->set('ip2location_geolocation', 'lookup_method', 'web_service');
   $this->set('ip2location_geolocation', 'api_key', 'YOUR_IP2LOCATION_API_KEY');
   ```

   **Note:** Please be aware that each query will consume 4 credits from your Web service account.

