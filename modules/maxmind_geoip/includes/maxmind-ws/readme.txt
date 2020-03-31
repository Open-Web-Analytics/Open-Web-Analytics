

MaxMindWebServices Class
========================

The MaxMindWebServices class is an abstract class that all MaxMind web services extend from. This class has no public access.

I have made a few assumptions in terms of the other services since I have yet to use them.

1. I assume that all services would have a need to look up:
  a. Country Codes
  b. SubCountry Codes
  c. Metro Codes
  
  As a result of this assumption I have public methods for translation of these codes in the MaxMindWebServices abstraction class.
  
2. Since it appears that not all the Web Services have the same interface I have stored the helper functions in the abstraction class anyway. Reason for this is to reduce code between multiple services.

Methods that all MaxMind Services have:
  setLicenceKey($key)
  isError()                     // return bool
  getResultArray()              // returns all returned values in an array
  lookupCountryCode($countryCode);
  lookupSubCountryCode($subcountryCode, $countryCode);
  lookupMetroCode($metroCode);
  
Notes:

  1. the lookup...Code() methods use lazy load, they don't parse the ini file unless the method is explicitly call.
  
  2. It is possible to set your licenceKey inside this class which then applies it as the default key for all MaxMind web services.
  


GeoCityLocateIspOrg
===================

This class Implements the Geo city location with ISP and Organization inforation included. This class is designed to be used as a singleton.

ex.
  Instead of $service = new GeoCityLocateIspOrg();
  
  use: $service = GeoCityLocateIspOrg::getInstance();
  
Methods:
  setIP($ip) // this is the trigger, all data is cleared and updated with new value
  getIP()
  getCountry()
  getRegion()
  getState() // alias of getRegion()
  getCity()
  getPostal()
  getZip() // alias of getPostal()
  getLatitude()
  getLat()  // alias of getLat()
  getLongitude()
  getLong() // alias of getLong()
  getMetroCode()
  getAreaCode()
  getISP()
  getOrganization()
  getError()
  
  And all generic methods listed above.


For Developers
==============

When adding a new Web Service to this library there are a few code interface details to remember.

1. All new interfaces must extend MaxMindWebServices
2. All services are responsible for building the url with the query string
3. All services must implement a getError() method
4. All methods must implement there own trigger to submit and recieve data
5. All data recieved must be stored in $this->data array, it doesn't matter if its an assoc array or not.

TODO
=========

1. change the _queryMaxMind() in the MaxMindWebService class to handle ssl connections as well.

2. The ini files that provide the lookup for the country, subcountry and metro codes appears to have issues. In order to remedy the issue I enclosed all keys with tick marks. This issue will be explored later.
